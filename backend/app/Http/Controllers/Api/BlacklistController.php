<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

/**
 * Sanction / blacklist list. Admin-only (routes/api.php): a blacklisted customer is
 * refused every new credit facility in every branch (Customer::assertNotBlacklisted),
 * so ordinary users must not be able to add or lift an entry.
 */
class BlacklistController extends Controller
{
    public function store(Request $request, Customer $customer)
    {
        $data = $request->validate(['reason' => 'required|string|max:500', 'note' => 'nullable|string|max:2000']);

        $customer->update(['status' => 'Blacklisted', 'blacklist_reason' => $data['reason'], 'blacklist_note' => $data['note'] ?? null]);

        return $customer->load('branch:id,name,code');
    }

    public function destroy(Customer $customer)
    {
        $customer->update(['status' => 'Active']);

        return $customer->load('branch:id,name,code');
    }
}
