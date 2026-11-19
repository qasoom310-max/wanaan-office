<?php

namespace App\Http\Controllers;

use App\Jobs\WriteRequirement;
use App\Models\Fact;
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
        $ideas = Idea::orderBy('id')->get();

        return view('ideas.index', [
            'sections' => collect(Idea::STATUSES)->map(fn (string $label, string $status): array => [
                'label' => $label,
                'ideas' => $ideas->where('status', $status)->values(),
            ]),
            'team' => $team,
        ]);
    }

    /**
     * The owner moves a request along: approve (the IT Manager then writes its
     * requirement), put it off, start building it, mark it done, or have the
     * requirement written again.
     */
    public function decide(Request $request, Idea $idea): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['yes', 'later', 'building', 'done', 'rewrite'])]]);

        $allowed = match ($data['status']) {
            'yes' => in_array($idea->status, ['wait', 'later'], true),
            'later' => $idea->status === 'wait',
            'building' => $idea->status === 'ready',
            'done' => in_array($idea->status, ['ready', 'building'], true),
            'rewrite' => in_array($idea->status, ['yes', 'ready'], true),
        };
        if (! $allowed) {
            return back()->withErrors(['status' => 'That step does not fit where this request is now.']);
        }

        if (in_array($data['status'], ['yes', 'rewrite'], true)) {
            $idea->update(['status' => 'yes', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'note' => null]);
            WriteRequirement::dispatchAfterResponse($idea);

            return back()->with('status', 'Approved. The IT Manager is writing the requirement.');
        }

        if ($data['status'] === 'done') {
            $idea->update(['status' => 'done', 'done_at' => now()]);
            // So nobody asks for it again, and the agent that asked knows.
            Fact::create(['text' => "Done on the IT board: {$idea->title}.", 'agents' => [$idea->agent], 'confirmed_by' => $request->user()->id]);

            return back()->with('status', 'Marked done. The team knows.');
        }

        $idea->update(['status' => $data['status'], 'decided_by' => $request->user()->id, 'decided_at' => now()]);

        return back()->with('status', Idea::STATUSES[$data['status']].'.');
    }
}
