<?php

namespace App\Office;

use App\Jobs\RunAgent;
use App\Models\Fact;
use App\Models\Message;
use App\Models\Question;

/**
 * What an agent is told before it answers, laid out for prompt caching:
 *
 * 1. office(): the office's rules and every confirmed fact — word for word the same for
 *    every agent, so it is cached once and reused by all of them;
 * 2. conversation(): the chat so far, also the same for every agent reading it (each
 *    message labelled with who wrote it), cached up to its last message;
 * 3. agent(): who this agent is, what it can see, the budget and its own spending —
 *    small, sent last, so it never breaks the cached part above it.
 */
class Instructions
{
    /** Messages from the end of the conversation whose files are shown in full. */
    private const FILES_SHOWN_FROM_LAST = 3;

    public function __construct(private Team $team, private Spending $spending) {}

    public function office(): string
    {
        $lines = [
            'You work in the Wanaan AI office. Wanaan is a business in Bahrain with a Rent A Car section, a Limousine section, a shop and accounting, run on its own ERP. The office is a team of AI agents, each with its own job; the owner leads it.',
            '',
            'Office rules:',
            '- The owner decides. You recommend; you never approve payments, change records or publish anything yourself.',
            '- Write plainly and briefly, in the language the owner wrote in (English or Arabic).',
            '- Say what you do not know. Never invent numbers, names, dates or records.',
            '- When you need information only the owner can give, ask one clear question.',
            '- When the owner decides something you think is wrong or risky for your area, say so plainly: what worries you, why, and what you would do instead. Speaking up is part of your job; once the owner has heard you, the owner decides.',
            '- The team shares one monthly AI budget. Whoever has the most important work uses most of it; nobody wastes it. Be ready to defend what you spend with what your work achieved.',
            '- In the conversation, every message starts with who wrote it in [brackets]. Messages labelled with your own name and title are yours.',
            '',
            'Facts the owner has confirmed (true; never ask about them again):',
        ];

        $facts = Fact::orderBy('id')->get();
        if ($facts->isEmpty()) {
            $lines[] = '- None yet.';
        }
        foreach ($facts as $fact) {
            $for = $fact->agents ? ' (for '.implode(', ', array_map(fn (string $agent): string => $this->team->exists($agent) ? $this->team->title($agent) : $agent, $fact->agents)).')' : '';
            $lines[] = '- '.$fact->text.$for;
        }

        $lines[] = '';
        $lines[] = 'Today is '.now()->format('l j F Y').'.';

        return implode("\n", $lines);
    }

    /**
     * The chat so far as the other side of the conversation, every message labelled,
     * with a cache marker on the last one.
     *
     * @return list<array{role: 'user', content: list<array<string, mixed>>}>
     */
    public function conversation(string $channel, ?int $ownerId): array
    {
        $messages = Message::with(['user', 'files'])->where('channel', $channel)
            ->when($ownerId === null, fn ($query) => $query->whereNull('owner_id'), fn ($query) => $query->where('owner_id', $ownerId))
            ->orderByDesc('id')->limit(config('office.history'))->get()->reverse()->values();

        // Files in the latest messages are shown as they are; older ones by name only,
        // so an old attachment does not make every answer more expensive.
        $showFrom = max(0, $messages->count() - self::FILES_SHOWN_FROM_LAST);

        $turns = [];
        foreach ($messages as $index => $message) {
            $author = $message->isFromAgent() ? $this->team->label($message->agent) : ($message->user?->name ?? 'Someone').' (owner)';
            $content = [['type' => 'text', 'text' => "[{$author}] {$message->body}"]];
            foreach ($message->files as $file) {
                if ($index >= $showFrom) {
                    array_push($content, ...$file->contentBlocks());
                } else {
                    $content[] = ['type' => 'text', 'text' => "[Attached earlier: {$file->name}, file #{$file->id}. Open it with office_open_file if you need it.]"];
                }
            }
            $turns[] = ['role' => 'user', 'content' => $content];
        }

        if ($turns !== []) {
            $last = count($turns) - 1;
            $lastBlock = count($turns[$last]['content']) - 1;
            $turns[$last]['content'][$lastBlock]['cacheControl'] = ['type' => 'ephemeral'];
        }

        return $turns;
    }

    /**
     * The part only this agent gets, sent after the conversation.
     */
    public function agent(string $agent, string $channel, bool $meeting = false, string $budget = Spending::NORMAL): string
    {
        $profile = $this->team->get($agent);

        $lines = [
            'You are '.$this->team->label($agent).'. '.$profile['brief'],
            ($this->spending->isManager($agent) ? 'You are a manager.' : 'You are an employee.').' Answer now, as '.$this->team->label($agent).', to the latest message.',
        ];

        $sources = $this->team->connectedSources($agent);
        if (in_array('erp', $sources, true)) {
            $lines[] = 'You can read the Wanaan ERP through your erp_ tools (read-only; your sections: '.implode(', ', $profile['erp'] ?? []).'). Look before you speak: base every figure on what the tools return, name the company and the period, and say when a list was cut short. Start with erp_companies.';
        }
        if (in_array('seo', $sources, true)) {
            $lines[] = 'You can read the SEO system through your seo_ tools (read-only). It decides which keywords the websites chase and what gets written; marketing follows it and never invents its own SEO decisions. Start with seo_projects.';
        }
        if (in_array('web', $sources, true)) {
            $lines[] = 'You can search the web for trends, competitors and facts about Bahrain and the Gulf. Name your sources, and never present what you found on the web as Wanaan\'s own data.';
        }
        if (in_array('office', $sources, true)) {
            $lines[] = 'You can read the office through your office_ tools: open questions, the IT board, spending and what the team did (never anyone\'s private chat).';
        }
        if (! in_array('erp', $sources, true) && ! in_array('seo', $sources, true)) {
            $lines[] = 'You cannot see Wanaan\'s company records (the ERP). Say so plainly when it matters, and never present a figure as Wanaan\'s own.';
        }
        $lines[] = 'When something is a colleague\'s job (their data, their research), ask them with office_ask_colleague instead of guessing.';
        $lines[] = 'If your work depends on something only the owner can answer, put it on the Questions page with office_ask_owner (at most '.Question::OPEN_PER_AGENT.' open).';
        if (($profile['manager'] ?? false) || $agent === 'it') {
            $lines[] = 'When the data or the system is not good enough for your work, push IT for the improvement with office_request_it. It waits on the IT board until the owner approves it. Never file again what the owner already approved, put off or saw done.';
        }

        if ($meeting) {
            array_push($lines,
                'This is a team meeting in the Everyone chat. The owner\'s last message is for the whole team, and every agent takes a turn, in order.',
                'Read it as it touches YOUR job. If it does: say in a few sentences what you will do about it, or, if you disagree, argue your case with reasons. You may agree or disagree with colleagues who spoke before you; do not repeat what they already said.',
                'If it does not touch your job and you have nothing important to add, reply with exactly: '.RunAgent::READ_ONLY_REPLY,
            );
        } else {
            $lines[] = $channel === Message::EVERYONE
                ? 'This is the Everyone chat: the whole team reads it. The owner asked you by name.'
                : 'This is a private chat with the owner. Do not repeat it in the Everyone chat.';
        }

        $month = $this->spending->thisMonth();
        $mine = $this->spending->agentThisMonth($agent);
        $lines[] = sprintf('Your AI spending this month: $%.2f, %d%% of the team\'s $%.2f.', $mine, $month > 0 ? round($mine / $month * 100) : 0, $month);

        if ($budget === Spending::TIGHT) {
            $lines[] = 'This month\'s AI budget is nearly used. Speak less: answer in a few short sentences, look things up only when the answer needs it, and still finish what you were asked to do.';
        } elseif ($budget === Spending::RESERVE) {
            $lines[] = 'This month\'s AI budget is used up. As a manager you may use a small reserve, but only for what really cannot wait: answer in two or three sentences, look up only what is essential, and tell the owner briefly that you are on the reserve.'
                .($meeting ? ' In this meeting, unless the message truly needs your answer now, reply with exactly '.RunAgent::READ_ONLY_REPLY.'.' : '');
        }

        return implode("\n", $lines);
    }
}
