<?php

namespace App\Http\Controllers;

use App\Jobs\RunAgent;
use App\Models\AgentRun;
use App\Models\Idea;
use App\Models\Message;
use App\Models\MessageFile;
use App\Office\Channels;
use App\Office\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    /**
     * Save the message first, then let the right agent work on it. A message counts as
     * sent only once it is saved; nothing that happens to the agent can lose it.
     */
    /** Open a file from a chat the person may read. */
    public function file(Request $request, MessageFile $file): StreamedResponse
    {
        $message = $file->message;
        $allowed = $message->channel === Message::EVERYONE ? $message->owner_id === null : $message->owner_id === $request->user()->id;
        abort_unless($allowed, 404);

        return Storage::disk('local')->response($file->path, $file->name, [
            'Content-Type' => $file->mime,
            // Shown in the browser (pictures, PDF) but never run as a page.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
        ], $file->isImage() || $file->isPdf() ? 'inline' : 'attachment');
    }

    public function store(Request $request, Channels $channels, Team $team): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:60'],
            'body' => ['nullable', 'string', 'max:8000', 'required_without:files'],
            'files' => ['nullable', 'array', 'max:'.MessageFile::PER_MESSAGE],
            'files.*' => ['file', 'mimes:'.MessageFile::ACCEPT, 'max:'.MessageFile::MAX_KB],
        ], ['files.*.mimes' => 'Pictures, PDF, Excel, CSV or text files only.', 'files.*.max' => 'Each file can be at most 10 MB.']);

        abort_unless($channels->isValid($data['channel']), 404);
        if (($ideaId = Channels::ideaId($data['channel'])) !== null) {
            abort_unless($request->user()->can('owner'), 403);
            abort_unless(Idea::find($ideaId)?->isOpenForDiscussion(), 422, 'This request is already decided.');
        }

        $ownerId = Message::isPrivate($data['channel']) ? $request->user()->id : null;
        $body = trim((string) ($data['body'] ?? ''));
        $files = $request->file('files', []);
        if ($body === '') {
            $body = count($files) === 1 ? 'Sent a file.' : 'Sent '.count($files).' files.';
        }

        $message = Message::create([
            'channel' => $data['channel'],
            'owner_id' => $ownerId,
            'user_id' => $request->user()->id,
            'body' => $body,
        ]);

        foreach ($files as $file) {
            MessageFile::create([
                'message_id' => $message->id,
                'name' => mb_substr($file->getClientOriginalName(), 0, 200),
                // Private disk, random name: only reachable through the chat, after a permission check.
                'path' => $file->store('chat-files/'.now()->format('Y/m'), 'local'),
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
            ]);
        }

        $answerer = $channels->answererFor($data['channel'], $body);
        $meeting = $data['channel'] === Message::EVERYONE && $team->mentionedIn($body) === null;

        // A team meeting: every agent reads it and takes its turn, in team order, each
        // seeing what the ones before it said. Otherwise one agent answers.
        $agents = $meeting ? array_keys($team->all()) : [$answerer];

        foreach ($agents as $position => $agent) {
            $run = AgentRun::create([
                'agent' => $agent,
                'channel' => $data['channel'],
                'owner_id' => $ownerId,
                'message_id' => $message->id,
                'meeting' => $meeting,
                'status' => $position === 0 ? AgentRun::WORKING : AgentRun::WAITING,
                'step' => $position === 0 ? 'Reading your message' : 'Waiting for their turn',
                'heartbeat_at' => now(),
            ]);

            RunAgent::dispatchAfterResponse($run);
        }

        return response()->json(['id' => $message->id]);
    }
}
