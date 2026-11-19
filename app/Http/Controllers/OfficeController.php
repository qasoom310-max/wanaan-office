<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Question;
use App\Models\User;
use App\Office\Channels;
use App\Office\Spending;
use App\Office\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OfficeController extends Controller
{
    /** Messages loaded at once per chat, newest last. */
    private const PAGE = 200;

    public function show(Request $request, Team $team, Channels $channels, Spending $spending): View
    {
        $user = $request->user();
        $with = $request->query('with');
        $agent = is_string($with) && $team->exists($with) ? $with : 'sec';
        $private = $channels->privateWith($agent);

        // How many messages each private chat already holds, for the picker.
        $counts = Message::where('owner_id', $user->id)->where('channel', '!=', Message::EVERYONE)
            ->selectRaw('channel, count(*) as total')->groupBy('channel')->pluck('total', 'channel');

        $pickerCounts = [];
        foreach (array_keys($team->all()) as $key) {
            $pickerCounts[$key] = (int) ($counts[$channels->privateWith($key)] ?? 0);
        }

        return view('office.show', [
            'team' => $team,
            'agent' => $agent,
            'privateChannel' => $private,
            'pickerCounts' => $pickerCounts,
            'history' => [
                Message::EVERYONE => $this->latest(Message::EVERYONE, $user, $team),
                $private => $this->latest($private, $user, $team),
            ],
            'reads' => StateController::reads($team),
            'spending' => $spending,
            'openQuestions' => Question::whereNull('answered_at')->get()->countBy('agent')->all(),
        ]);
    }

    /** The 3D office: the same live data as the desks, as a room. */
    public function scene(Team $team): View
    {
        $agents = collect(array_keys($team->all()))->map(fn (string $agent): array => [
            'key' => $agent,
            'name' => $team->displayName($agent),
            'title' => $team->title($agent),
            'team' => $team->get($agent)['team'],
            'manager' => (bool) ($team->get($agent)['manager'] ?? false),
            'chat' => route('office', ['with' => $agent]).'#chats',
        ])->values();

        return view('office.scene', [
            'sceneData' => [
                'agents' => $agents,
                'teams' => config('office.teams'),
                'stateUrl' => route('office.state'),
                'questionsUrl' => route('questions'),
            ],
        ]);
    }

    /** One chat's latest messages, for switching the private chat without reloading. */
    public function history(Request $request, Team $team, Channels $channels): JsonResponse
    {
        $channel = (string) $request->query('channel');
        abort_unless($channels->isValid($channel), 404);

        return response()->json(['channel' => $channel, 'messages' => $this->latest($channel, $request->user(), $team)]);
    }

    /**
     * @return list<array{id: int, mine: bool, author: string, body: string, at: string}>
     */
    private function latest(string $channel, User $user, Team $team): array
    {
        return Message::with(['user', 'files'])->visibleIn($channel, $user)
            ->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values()
            ->map(fn (Message $message): array => StateController::shape($message, $team))->all();
    }
}
