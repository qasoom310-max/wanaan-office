<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Question;
use App\Office\Channels;
use App\Office\Team;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OfficeController extends Controller
{
    /** Messages loaded with the page per chat; older ones load on request. */
    private const FIRST_PAGE = 200;

    public function show(Request $request, Team $team, Channels $channels): View
    {
        $user = $request->user();
        $with = $request->query('with');
        $direct = is_string($with) && $team->exists($with) ? $with : null;

        $chats = [Message::SECRETARY, Message::EVERYONE];
        if ($direct !== null) {
            $chats[] = $channels->direct($direct);
        }

        $history = [];
        foreach ($chats as $channel) {
            $history[$channel] = Message::with('user')->visibleIn($channel, $user)
                ->orderByDesc('id')->limit(self::FIRST_PAGE)->get()->reverse()->values()
                ->map(fn (Message $message): array => StateController::shape($message, $team))->all();
        }

        return view('office.show', [
            'team' => $team,
            'channels' => $channels,
            'direct' => $direct,
            'chats' => $chats,
            'history' => $history,
            'reads' => StateController::reads($team),
            'openQuestions' => Question::whereNull('answered_at')->get()->countBy('agent')->all(),
        ]);
    }
}
