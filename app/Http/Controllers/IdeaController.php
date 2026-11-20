<?php

namespace App\Http\Controllers;

use App\Jobs\RunAgent;
use App\Jobs\WriteRequirement;
use App\Models\AgentRun;
use App\Models\Fact;
use App\Models\Idea;
use App\Models\Message;
use App\Office\Channels;
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
            'yes' => in_array($idea->status, ['wait', 'rethink', 'later'], true),
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

    /**
     * The owner sends a request back with the reason it cannot be done as asked. The
     * reason goes to the agent who asked, in their private chat (kept for good), and
     * they answer: a changed request that fits, or another way to get what they need.
     */
    public function reconsider(Request $request, Idea $idea, Channels $channels, Team $team): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        if (! in_array($idea->status, ['wait', 'later'], true)) {
            return back()->withErrors(['status' => 'That step does not fit where this request is now.']);
        }

        $reason = trim($data['reason']);
        $idea->update(['status' => 'rethink', 'owner_reason' => $reason, 'decided_by' => $request->user()->id, 'decided_at' => now()]);

        if (! $team->exists($idea->agent)) {
            return back()->with('status', 'Sent back with your reason.');
        }

        $channel = $channels->direct($idea->agent);
        $message = Message::create([
            'channel' => $channel,
            'owner_id' => $request->user()->id,
            'user_id' => $request->user()->id,
            'body' => "(From the IT board) About your request \"{$idea->title}\": I can't approve it as it is.\n\nWhy: {$reason}\n\n"
                .'Take this into account. If a changed request would fit, file it again with office_request_it (it replaces this one). '
                .'If not, tell me another way to get what you need, or say plainly that it cannot be done for now.',
        ]);

        RunAgent::dispatchAfterResponse(AgentRun::create([
            'agent' => $idea->agent,
            'channel' => $channel,
            'owner_id' => $request->user()->id,
            'message_id' => $message->id,
            'status' => AgentRun::WORKING,
            'step' => 'Reading your reason',
            'heartbeat_at' => now(),
        ]));

        return back()->with('status', 'Sent back to '.$team->label($idea->agent).' with your reason. Their answer is in your private chat with them.');
    }
}
