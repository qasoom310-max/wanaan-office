<?php

namespace App\Office;

use App\Jobs\RunAgent;
use App\Models\Fact;
use App\Models\Message;

/**
 * What an agent is told before it answers: who it is, the office's rules, what data it
 * really has, the confirmed facts, and the conversation so far.
 */
class Instructions
{
    public function __construct(private Team $team) {}

    public function for(string $agent, string $channel, bool $meeting = false): string
    {
        $profile = $this->team->get($agent);
        $name = $this->team->nameOf($agent);

        $lines = [
            'You work in the Wanaan AI office. Wanaan is a business in Bahrain with a Rent A Car section, a Limousine section, a shop and accounting, run on its own ERP.',
            '',
            'You are the '.$profile['title'].($name !== null ? ', named '.$name : '').'. '.$profile['brief'],
            '',
            'Office rules:',
            '- The owner decides. You recommend; you never approve payments, change records or publish anything yourself.',
            '- Write plainly and briefly, in the language the owner wrote in (English or Arabic).',
            '- Say what you do not know. Never invent numbers, names, dates or records.',
            '- When you need information only the owner can give, ask one clear question.',
            '- When the owner decides something you think is wrong or risky for your area, say so plainly: what worries you, why, and what you would do instead. Speaking up is part of your job; once the owner has heard you, the owner decides.',
        ];

        if (in_array('erp', $this->team->connectedSources($agent), true)) {
            $lines[] = '- You can read the Wanaan ERP through your erp_ tools (read-only). Look before you speak: base every figure on what the tools return, name the company and the period, and say when a list was cut short. Start with erp_companies.';
        } else {
            $lines[] = '- You are not connected to any company data yet. You can talk, plan and give general advice, but say plainly that you cannot see the real records, and never present a figure as Wanaan\'s own.';
        }

        if ($meeting) {
            array_push($lines,
                '',
                'This is a team meeting in the Everyone chat. The owner\'s last message is for the whole team, and every agent takes a turn, in order.',
                '- Read it as it touches YOUR job. If it does: say in a few sentences what you will do about it, or, if you disagree, argue your case with reasons.',
                '- You may agree or disagree with colleagues who spoke before you. Do not repeat what they already said.',
                '- If it does not touch your job and you have nothing important to add, reply with exactly: '.RunAgent::READ_ONLY_REPLY,
            );
        } else {
            $lines[] = $channel === Message::EVERYONE
                ? '- This is the Everyone chat: the whole team reads it. The owner asked you by name.'
                : '- This is a private chat with the owner. Do not repeat it in the Everyone chat.';
        }

        $facts = Fact::orderBy('id')->get()
            ->filter(fn (Fact $fact): bool => $fact->agents === null || $fact->agents === [] || in_array($agent, $fact->agents, true));

        $lines[] = '';
        $lines[] = 'Facts the owner has confirmed (true, never ask about them again):';
        if ($facts->isEmpty()) {
            $lines[] = '- None yet.';
        }
        foreach ($facts as $fact) {
            $lines[] = '- '.$fact->text;
        }

        $lines[] = '';
        $lines[] = 'Today is '.now()->format('l j F Y').'.';

        return implode("\n", $lines);
    }

    /**
     * The conversation as the agent sees it: its own messages as its turns, everyone
     * else's as the other side, each labelled with who wrote it.
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public function conversation(string $agent, string $channel, ?int $ownerId): array
    {
        $messages = Message::with('user')->where('channel', $channel)
            ->when($ownerId === null, fn ($query) => $query->whereNull('owner_id'), fn ($query) => $query->where('owner_id', $ownerId))
            ->orderByDesc('id')->limit(config('office.history'))->get()->reverse()->values();

        $turns = [];
        foreach ($messages as $message) {
            $mine = $message->agent === $agent;
            $author = $message->isFromAgent() ? $this->team->label($message->agent) : ($message->user?->name ?? 'Someone');
            $turns[] = [
                'role' => $mine ? 'assistant' : 'user',
                'content' => $mine ? $message->body : "[{$author}] {$message->body}",
            ];
        }

        // The conversation must open with the other side.
        while ($turns !== [] && $turns[0]['role'] === 'assistant') {
            array_shift($turns);
        }

        return $turns;
    }
}
