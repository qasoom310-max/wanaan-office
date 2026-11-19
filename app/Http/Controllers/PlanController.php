<?php

namespace App\Http\Controllers;

use App\Models\PlanPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        $pages = PlanPage::orderBy('sort')->orderBy('id')->get();

        return view('plan.index', [
            'pages' => $pages,
            'counts' => $pages->countBy('status')->all(),
        ]);
    }

    /** The owner approves a page as it is, or asks for changes with a note. */
    public function decide(Request $request, PlanPage $page): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'changes', 'built', 'live'])],
            'note' => ['nullable', 'string', 'max:2000', 'required_if:decision,changes'],
        ], ['note.required_if' => 'Say what should change.']);

        if ($data['decision'] === 'built') {
            abort_unless(in_array($page->status, ['approved', 'building'], true), 422, 'Only an approved page can be marked built.');
        }
        if ($data['decision'] === 'live') {
            abort_unless($page->status === 'built', 422, 'Only a built page can go live.');
        }

        $page->update(match ($data['decision']) {
            'approve' => ['status' => 'approved', 'approved_at' => now(), 'owner_note' => null],
            'changes' => ['status' => 'changes', 'owner_note' => $data['note']],
            'built' => ['status' => 'built'],
            'live' => ['status' => 'live'],
        });

        return back()->with('status', "“{$page->title}”: {$page->statusName()}.");
    }
}
