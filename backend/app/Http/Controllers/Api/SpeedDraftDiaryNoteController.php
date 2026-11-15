<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SpeedDraft;
use Illuminate\Http\Request;

class SpeedDraftDiaryNoteController extends Controller
{
    public function index(SpeedDraft $speedDraft)
    {
        return $speedDraft->diaryNotes()->with('creator:id,name')->get();
    }

    public function store(Request $request, SpeedDraft $speedDraft)
    {
        $data = $request->validate([
            'note' => 'required|string|max:500',
            'follow_up_date' => 'nullable|date',
        ]);

        $note = $speedDraft->diaryNotes()->create([
            'note' => $data['note'],
            'follow_up_date' => $data['follow_up_date'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($note->load('creator:id,name'), 201);
    }

    public function destroy(\App\Models\FacilityDiaryNote $note)
    {
        $note->delete();

        return response()->noContent();
    }
}
