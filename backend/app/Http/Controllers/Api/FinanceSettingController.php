<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinanceSetting;
use Illuminate\Http\Request;

/**
 * Finance-wide toggles — see App\Models\FinanceSetting. A flat key/value
 * screen; each key has a known shape validated below.
 */
class FinanceSettingController extends Controller
{
    private const BOOL_KEYS = ['require_cash_session', 'reversal_requires_approval'];

    public function index()
    {
        return FinanceSetting::map();
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'nullable',
        ]);

        // The books-closed date: no ledger entry dated on or before it can be posted. Empty clears the lock.
        if (array_key_exists('ledger_locked_through', $data['settings'])) {
            $request->validate(['settings.ledger_locked_through' => 'nullable|date_format:Y-m-d']);
        }

        foreach ($data['settings'] as $key => $value) {
            if (in_array($key, self::BOOL_KEYS, true)) {
                FinanceSetting::put($key, filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0', $request->user()->id);
            } elseif ($key === 'ledger_locked_through') {
                FinanceSetting::put($key, $value ?? '', $request->user()->id);
            }
        }

        return FinanceSetting::map();
    }
}
