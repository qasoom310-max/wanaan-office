<?php

namespace App\Http\Controllers;

use App\Models\Idea;
use App\Office\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IdeaController extends Controller
{
    public function index(Team $team): View
    {
        $order = ['wait' => 0, 'yes' => 1, 'later' => 2, 'done' => 3];

        return view('ideas.index', [
            'ideas' => Idea::orderBy('id')->get()->sortBy(fn (Idea $idea): int => $order[$idea->status] ?? 9)->values(),
            'team' => $team,
        ]);
    }

    /** Only the owner approves IT work, or puts it off. */
    public function decide(Request $request, Idea $idea): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['yes', 'later'])]]);

        $idea->update(['status' => $data['status'], 'decided_by' => $request->user()->id, 'decided_at' => now()]);

        return back()->with('status', $data['status'] === 'yes' ? 'Approved.' : 'Put off for later.');
    }
}
