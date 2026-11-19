<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\Message;
use App\Office\Channels;
use App\Office\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the office page asks for every couple of seconds: new messages in the chats it
 * shows, and what each agent is doing right now.
 */
class StateController extends Controller
{
    public function show(Request $request, Channels $channels, Team $team): JsonResponse
    {
        $user = $request->user();
        $after = (array) $request->query('after', []);

        $messages = [];
        foreach ($after as $channel => $lastId) {
            if (! is_string($channel) || ! $channels->isValid($channel)) {
                continue;
            }
            $messages[$channel] = Message::with('user')->visibleIn($channel, $user)
                ->where('id', '>', (int) $lastId)->orderBy('id')->limit(200)->get()
                ->map(fn (Message $message): array => self::shape($message, $team))->all();
        }

        // Only runs this person may see: the shared chat's, and their own private ones.
        $runs = AgentRun::where('status', AgentRun::WORKING)
            ->where(fn ($query) => $query->whereNull('owner_id')->orWhere('owner_id', $user->id))
            ->orderBy('id')->get()
            ->map(fn (AgentRun $run): array => [
                'id' => $run->id,
                'agent' => $run->agent,
                'name' => $team->displayName($run->agent),
                'channel' => $run->channel,
                'step' => $run->step,
                'step_number' => $run->step_number,
                'step_total' => $run->step_total,
                'stuck' => $run->isStuck(),
                'seconds' => (int) $run->created_at->diffInSeconds(now()),
            ])->all();

        return response()->json(['messages' => $messages, 'runs' => $runs]);
    }

    /**
     * @return array{id: int, mine: bool, author: string, body: string, at: string}
     */
    public static function shape(Message $message, Team $team): array
    {
        return [
            'id' => $message->id,
            'mine' => ! $message->isFromAgent(),
            'author' => $message->isFromAgent() ? $team->label($message->agent) : ($message->user?->name ?? 'Someone'),
            'body' => $message->body,
            'at' => $message->created_at->toIso8601String(),
        ];
    }
}
