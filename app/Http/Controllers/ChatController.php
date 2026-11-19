<?php

namespace App\Http\Controllers;

use App\Jobs\RunAgent;
use App\Models\AgentRun;
use App\Models\Message;
use App\Office\Channels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    /**
     * Save the message first, then let the right agent work on it. A message counts as
     * sent only once it is saved; nothing that happens to the agent can lose it.
     */
    public function store(Request $request, Channels $channels): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:8000'],
        ]);

        abort_unless($channels->isValid($data['channel']), 404);

        $ownerId = Message::isPrivate($data['channel']) ? $request->user()->id : null;
        $body = trim($data['body']);

        $message = Message::create([
            'channel' => $data['channel'],
            'owner_id' => $ownerId,
            'user_id' => $request->user()->id,
            'body' => $body,
        ]);

        $run = AgentRun::create([
            'agent' => $channels->answererFor($data['channel'], $body),
            'channel' => $data['channel'],
            'owner_id' => $ownerId,
            'message_id' => $message->id,
            'status' => AgentRun::WORKING,
            'step' => 'Reading your message',
            'heartbeat_at' => now(),
        ]);

        RunAgent::dispatchAfterResponse($run);

        return response()->json(['id' => $message->id]);
    }
}
