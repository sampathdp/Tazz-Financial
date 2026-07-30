<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankController extends Controller
{
    public function index()
    {
        return Bank::orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $bank = Bank::create($data);

        return response()->json($bank, 201);
    }

    public function update(Request $request, Bank $bank)
    {
        $data = $this->validated($request, $bank);
        $bank->update($data);

        return $bank;
    }

    public function destroy(Bank $bank)
    {
        $bank->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Bank $bank = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:128', Rule::unique('banks', 'name')->ignore($bank?->id)],
            'code' => 'nullable|string|max:32',
            'description' => 'nullable|string|max:255',
            'status' => [Rule::in(['Active', 'Inactive'])],
            'sort_order' => 'nullable|integer',
        ]);
    }
}
