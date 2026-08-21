<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FacilityCollateral;
use App\Models\FacilityCollateralPawnDetail;
use App\Models\Pawning;
use App\Models\PawningAuction;
use App\Models\PawningAuctionItem;
use App\Services\NumberGenerator;
use App\Support\PawningInterestCalculator;
use App\Support\PawningLedgerPoster;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Default handling — batching, listing, sale recording, surplus settlement, and releasing the ticket once its collateral is gone. GL posting (capital recovery, interest recognition, surplus-payable liability) is PawningLedgerPoster::postAuctionSale/postSurplusRefund/postSurplusForfeiture. */
class PawningAuctionController extends Controller
{
    public function index(Request $request)
    {
        return PawningAuction::with('branch:id,name,code', 'creator:id,name')
            ->withCount('items')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('branch_id'), fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->orderByDesc('scheduled_date')
            ->get();
    }

    public function show(PawningAuction $pawningAuction)
    {
        return $pawningAuction->load(
            'branch:id,name,code',
            'creator:id,name',
            'items.collateral.pawnDetail.articleType',
            'items.facility:id,code,customer_id',
            'items.facility.customer:id,full_name,code,nic_no'
        );
    }

    /**
     * Pledged articles whose ticket is past repay_due_date + the scheme's
     * auction_eligible_days, and not already listed on an open (Scheduled)
     * auction — the pool a new auction batch is built from.
     */
    public function eligible(Request $request)
    {
        $today = Carbon::today();
        $branchId = $request->query('branch_id');

        $listedIds = PawningAuctionItem::whereIn('status', ['Listed'])
            ->whereHas('auction', fn ($q) => $q->where('status', 'Scheduled'))
            ->pluck('facility_collateral_id');

        return Pawning::where('status', 'Active')
            ->when($branchId, fn ($q, $b) => $q->where('branch_id', $b))
            ->with(['collateral' => fn ($q) => $q->where('collateral_type', 'PawnedJewelry')
                ->whereHas('pawnDetail', fn ($q2) => $q2->where('item_status', 'Pledged'))
                ->whereNotIn('id', $listedIds)
                ->with('pawnDetail.articleType')])
            ->whereHas('pawningDetail.pawningType')
            ->get()
            ->map(function (Pawning $pawning) use ($today) {
                $detail = $pawning->pawningDetail;
                $eligibleFrom = Carbon::parse($detail->repay_due_date)->addDays((int) $detail->pawningType->auction_eligible_days);
                if ($today->lt($eligibleFrom) || $pawning->collateral->isEmpty()) {
                    return null;
                }
                $pawning->setAttribute('daysPastEligible', (int) $eligibleFrom->diffInDays($today));

                return $pawning;
            })
            ->filter()
            ->values()
            ->load('customer:id,full_name,code,nic_no,phone', 'branch:id,name,code');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'scheduled_date' => 'required|date',
            'auctioneer_name' => 'nullable|string|max:128',
            'venue' => 'nullable|string|max:255',
        ]);
        $branch = Branch::findOrFail($data['branch_id']);

        $auction = DB::transaction(function () use ($data, $branch, $request) {
            $auctionNo = NumberGenerator::next('pawning_auction', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'user_id' => $request->user()->id,
            ]);
            $auction = PawningAuction::create(array_merge($data, [
                'auction_no' => $auctionNo,
                'status' => 'Scheduled',
                'created_by' => $request->user()->id,
            ]));
            NumberGenerator::attach($auctionNo, $auction);

            return $auction;
        });

        return response()->json($auction->load('branch:id,name,code'), 201);
    }

    /** Adds eligible articles to a Scheduled auction, snapshotting each article's own proportional outstanding capital/interest at today's cycle position. */
    public function addItems(Request $request, PawningAuction $pawningAuction)
    {
        if ($pawningAuction->status !== 'Scheduled') {
            return response()->json(['message' => 'Items can only be added to a Scheduled auction.'], 422);
        }
        $data = $request->validate([
            'facility_collateral_ids' => 'required|array|min:1',
            'facility_collateral_ids.*' => 'integer|exists:facility_collateral,id',
        ]);

        DB::transaction(function () use ($pawningAuction, $data) {
            foreach (array_unique($data['facility_collateral_ids']) as $collateralId) {
                $collateral = FacilityCollateral::with('pawnDetail')->findOrFail($collateralId);
                if ($collateral->collateral_type !== 'PawnedJewelry' || $collateral->pawnDetail?->item_status !== 'Pledged') {
                    continue;
                }
                if (PawningAuctionItem::where('facility_collateral_id', $collateralId)
                    ->where('status', 'Listed')
                    ->whereHas('auction', fn ($q) => $q->where('status', 'Scheduled'))
                    ->exists()) {
                    continue;
                }
                $pawning = Pawning::findOrFail($collateral->credit_facility_id);
                $quote = PawningInterestCalculator::quoteForArticles($pawning, [$collateralId]);

                PawningAuctionItem::create([
                    'pawning_auction_id' => $pawningAuction->id,
                    'facility_collateral_id' => $collateralId,
                    'credit_facility_id' => $pawning->id,
                    'outstanding_capital' => $quote['capital'],
                    'outstanding_interest' => $quote['interest'],
                    'status' => 'Listed',
                ]);
            }
        });

        return response()->json($pawningAuction->load('items.collateral.pawnDetail.articleType'), 201);
    }

    /**
     * Records the hammer price for one lot. surplus_amount (hammer minus
     * outstanding capital+interest, floored at 0) is owed back to the
     * pledger by law, not kept as lender profit — settlement_status starts
     * Pending and is updated separately once actually paid out/claimed.
     * The ticket's outstanding balance is reduced by this item's snapshot
     * capital regardless of whether the hammer price covered it — once
     * sold, the debt against this specific article is extinguished either
     * way (a shortfall is a write-off, not something chased further).
     */
    public function recordSale(Request $request, PawningAuctionItem $pawningAuctionItem)
    {
        if ($pawningAuctionItem->status !== 'Listed') {
            return response()->json(['message' => 'Only a Listed lot can have a sale recorded.'], 422);
        }
        $data = $request->validate([
            'hammer_price' => 'required|numeric|min:0.01',
            'buyer_name' => 'nullable|string|max:128',
            'buyer_nic' => 'nullable|string|max:15',
        ]);

        $item = DB::transaction(function () use ($pawningAuctionItem, $data, $request) {
            $owed = (float) $pawningAuctionItem->outstanding_capital + (float) $pawningAuctionItem->outstanding_interest;
            $surplus = max(0, round($data['hammer_price'] - $owed, 2));

            $pawningAuctionItem->update([
                'hammer_price' => $data['hammer_price'],
                'buyer_name' => $data['buyer_name'] ?? null,
                'buyer_nic' => $data['buyer_nic'] ?? null,
                'surplus_amount' => $surplus,
                'status' => 'Sold',
                'sold_at' => now(),
            ]);

            FacilityCollateralPawnDetail::where('facility_collateral_id', $pawningAuctionItem->facility_collateral_id)
                ->update(['item_status' => 'Auctioned']);

            $pawning = Pawning::findOrFail($pawningAuctionItem->credit_facility_id);
            $newOutstanding = max(0, round((float) $pawning->outstanding_balance_cache - (float) $pawningAuctionItem->outstanding_capital, 2));
            $remainingPledged = $pawning->collateral()
                ->where('collateral_type', 'PawnedJewelry')
                ->whereHas('pawnDetail', fn ($q) => $q->where('item_status', 'Pledged'))
                ->count();
            $pawning->update(['outstanding_balance_cache' => $newOutstanding]);
            if ($remainingPledged === 0) {
                $pawning->update(['status' => 'Closed', 'closed_at' => now(), 'outstanding_balance_cache' => 0]);
            }

            PawningLedgerPoster::postAuctionSale($pawningAuctionItem->fresh(), $request->user()->id);

            return $pawningAuctionItem;
        });

        return $item->fresh();
    }

    /** No bidder met reserve — the article stays Pledged, eligible for a future auction round. */
    public function markUnsold(PawningAuctionItem $pawningAuctionItem)
    {
        if ($pawningAuctionItem->status !== 'Listed') {
            return response()->json(['message' => 'Only a Listed lot can be marked unsold.'], 422);
        }
        $pawningAuctionItem->update(['status' => 'Unsold']);

        return $pawningAuctionItem;
    }

    /** Pays out a Sold lot's surplus to the pledger — Dr 2110, Cr till (PawningLedgerPoster::postSurplusRefund). */
    public function settleSurplus(Request $request, PawningAuctionItem $pawningAuctionItem)
    {
        if ($pawningAuctionItem->status !== 'Sold' || (float) $pawningAuctionItem->surplus_amount <= 0) {
            return response()->json(['message' => 'This lot has no surplus to settle.'], 422);
        }
        if ($pawningAuctionItem->settlement_status !== 'Pending') {
            return response()->json(['message' => 'This surplus has already been settled.'], 422);
        }

        DB::transaction(function () use ($pawningAuctionItem, $request) {
            $pawningAuctionItem->update(['settlement_status' => 'Refunded']);
            PawningLedgerPoster::postSurplusRefund($pawningAuctionItem, $request->user()->id);
        });

        return $pawningAuctionItem->fresh();
    }

    /** Surplus never claimed within the branch's window — becomes the company's (Dr 2110, Cr 4900). */
    public function forfeitSurplus(Request $request, PawningAuctionItem $pawningAuctionItem)
    {
        if ($pawningAuctionItem->status !== 'Sold' || (float) $pawningAuctionItem->surplus_amount <= 0) {
            return response()->json(['message' => 'This lot has no surplus to forfeit.'], 422);
        }
        if ($pawningAuctionItem->settlement_status !== 'Pending') {
            return response()->json(['message' => 'This surplus has already been settled.'], 422);
        }

        DB::transaction(function () use ($pawningAuctionItem, $request) {
            $pawningAuctionItem->update(['settlement_status' => 'Forfeited']);
            PawningLedgerPoster::postSurplusForfeiture($pawningAuctionItem, $request->user()->id);
        });

        return $pawningAuctionItem->fresh();
    }

    /** Closes the batch once every lot has a sale recorded or is marked unsold. */
    public function complete(PawningAuction $pawningAuction)
    {
        if ($pawningAuction->items()->where('status', 'Listed')->exists()) {
            return response()->json(['message' => 'Every lot must be sold or marked unsold before completing this auction.'], 422);
        }
        $pawningAuction->update(['status' => 'Completed']);

        return $pawningAuction->fresh();
    }
}
