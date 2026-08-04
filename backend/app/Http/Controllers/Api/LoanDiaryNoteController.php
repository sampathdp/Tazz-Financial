<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacilityDiaryNote;
use App\Models\Loan;
use Illuminate\Http\Request;

class LoanDiaryNoteController extends Controller
{
    public function index(Loan $loan)
    {
        return $loan->diaryNotes()->with('creator:id,name')->get();
    }

    public function store(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'note' => 'required|string|max:500',
            'follow_up_date' => 'nullable|date',
        ]);

        $note = $loan->diaryNotes()->create([
            'note' => $data['note'],
            'follow_up_date' => $data['follow_up_date'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($note->load('creator:id,name'), 201);
    }

    public function destroy(FacilityDiaryNote $note)
    {
        $note->delete();

        return response()->noContent();
    }
}
