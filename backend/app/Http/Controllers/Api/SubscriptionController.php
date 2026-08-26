<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $query = Subscription::with(['branch:id,name,code', 'creator:id,name'])
            ->orderBy('expiry_date');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('provider', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%");
            });
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        $subscription = Subscription::create($data);

        return response()->json($subscription->load(['branch:id,name,code', 'creator:id,name']), 201);
    }

    public function update(Request $request, Subscription $subscription)
    {
        $subscription->update($this->validated($request));

        return $subscription->load(['branch:id,name,code', 'creator:id,name']);
    }

    public function destroy(Subscription $subscription)
    {
        $subscription->delete();

        return response()->noContent();
    }

    /** Active items inside their own reminder window — feeds the NotificationBell. */
    public function dueSoon()
    {
        return Subscription::dueSoon()->orderBy('expiry_date')->get();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'type' => [Rule::in(['Domain', 'Hosting', 'Software License', 'Legal Document', 'Insurance', 'Other'])],
            'provider' => 'nullable|string|max:150',
            'reference_no' => 'nullable|string|max:100',
            'cost' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'start_date' => 'nullable|date',
            'expiry_date' => 'required|date',
            'reminder_days_before' => 'nullable|integer|min:0|max:3650',
            'status' => [Rule::in(['Active', 'Inactive', 'Cancelled'])],
            'branch_id' => 'nullable|exists:branches,id',
            'notes' => 'nullable|string',
        ]);
    }
}
