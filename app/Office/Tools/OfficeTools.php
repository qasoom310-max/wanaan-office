<?php

namespace App\Office\Tools;

use App\Models\AgentRun;
use App\Models\Idea;
use App\Models\Message;
use App\Models\Question;
use App\Office\Spending;
use App\Office\Team;

/**
 * The office itself as tools: what the Secretary reads to keep track, and the two
 * things any agent may add — a question for the owner and a request to IT. Nothing an
 * agent adds takes effect until the owner answers or approves it.
 */
class OfficeTools
{
    /** Requests to IT one agent may have waiting at once. */
    private const OPEN_REQUESTS_PER_AGENT = 5;

    public function __construct(private Team $team, private Spending $spending) {}

    /**
     * Reading the office: open questions, the IT board, spending, recent team chat.
     *
     * @return list<AgentTool>
     */
    public function reading(): array
    {
        return [
            new AgentTool('office_open_questions', 'Every question the agents are waiting for the owner to answer, and who asked.',
                ['type' => 'object', 'properties' => new \stdClass],
                fn (): string => 'Opening the office: open questions',
                fn (): string => $this->json(Question::whereNull('answered_at')->orderBy('id')->get()
                    ->map(fn (Question $q): array => ['asked_by' => $this->team->label($q->agent), 'question' => $q->question, 'why' => $q->why, 'since' => $q->created_at->toDateString()])->all())),
            new AgentTool('office_it_board', 'The IT board: every request to IT, who asked, its size and whether it waits for the owner, is approved, put off or done.',
                ['type' => 'object', 'properties' => new \stdClass],
                fn (): string => 'Opening the office: the IT board',
                fn (): string => $this->json(Idea::orderBy('id')->get()
                    ->map(fn (Idea $idea): array => ['asked_by' => $this->team->exists($idea->agent) ? $this->team->label($idea->agent) : $idea->agent, 'title' => $idea->title, 'why' => $idea->why, 'size' => $idea->size, 'status' => Idea::STATUSES[$idea->status] ?? $idea->status])->all())),
            new AgentTool('office_spending', 'AI spending this month: the total, the budget, and each agent\'s share.',
                ['type' => 'object', 'properties' => new \stdClass],
                fn (): string => 'Opening the office: spending',
                fn (): string => $this->json([
                    'this_month' => round($this->spending->thisMonth(), 2), 'today' => round($this->spending->today(), 2),
                    'budget' => $this->spending->budget(), 'hard_ceiling' => $this->spending->ceiling(),
                    'by_agent' => collect($this->spending->byAgentThisMonth())->mapWithKeys(fn (float $cost, string $agent): array => [$this->team->label($agent) => round($cost, 2)])->all(),
                ])),
            new AgentTool('office_team_activity', 'What the team did lately: the last messages in the Everyone chat and each agent\'s work in the last days (never anyone\'s private chat).',
                ['type' => 'object', 'properties' => ['days' => ['type' => 'integer', 'description' => 'How many days back, 1 to 14 (default 2).']]],
                fn (): string => 'Opening the office: what the team did',
                function (array $in): string {
                    $since = now()->subDays(max(1, min(14, (int) ($in['days'] ?? 2))));

                    return $this->json([
                        'everyone_chat' => Message::with('user')->where('channel', Message::EVERYONE)->where('created_at', '>=', $since)->orderBy('id')->limit(200)->get()
                            ->map(fn (Message $m): array => ['from' => $m->isFromAgent() ? $this->team->label($m->agent) : ($m->user?->name ?? 'Owner'), 'at' => $m->created_at->format('Y-m-d H:i'), 'text' => $m->body])->all(),
                        'work_by_agent' => AgentRun::where('created_at', '>=', $since)->selectRaw('agent, count(*) as answers, sum(status = ?) as failed', [AgentRun::FAILED])->groupBy('agent')->get()
                            ->map(fn ($row): array => ['agent' => $this->team->label($row->agent), 'answers' => (int) $row->answers, 'failed' => (int) $row->failed])->all(),
                    ]);
                }),
        ];
    }

    /**
     * Ask the owner something only the owner can answer. It waits on the Questions page.
     */
    public function askOwner(string $agent): AgentTool
    {
        return new AgentTool('office_ask_owner', 'Put a question on the owner\'s Questions page, when only the owner can give the answer and your work depends on it. Their answer becomes a fact you will always read. At most '.Question::OPEN_PER_AGENT.' open at a time.',
            ['type' => 'object', 'properties' => [
                'question' => ['type' => 'string', 'description' => 'One clear question.'],
                'why' => ['type' => 'string', 'description' => 'What it blocks, in one sentence.'],
            ], 'required' => ['question', 'why']],
            fn (): string => 'Adding a question for you',
            function (array $in) use ($agent): string {
                $question = trim((string) ($in['question'] ?? ''));
                if ($question === '') {
                    return 'Not added: the question was empty.';
                }
                $open = Question::where('agent', $agent)->whereNull('answered_at');
                if ((clone $open)->whereRaw('lower(question) = ?', [mb_strtolower($question)])->exists()) {
                    return 'Already on the Questions page; waiting for the owner.';
                }
                if ($open->count() >= Question::OPEN_PER_AGENT) {
                    return 'Not added: you already have '.Question::OPEN_PER_AGENT.' open questions. Wait for answers first.';
                }
                Question::create(['agent' => $agent, 'question' => mb_substr($question, 0, 500), 'why' => mb_substr(trim((string) ($in['why'] ?? '')), 0, 500)]);

                return 'Added to the owner\'s Questions page.';
            });
    }

    /**
     * Ask IT for an improvement. It waits on the IT board until the owner approves it.
     */
    public function requestIt(string $agent): AgentTool
    {
        return new AgentTool('office_request_it', 'Ask IT for an improvement your work needs, for example better data entry in the ERP. It goes on the IT board and waits until the owner approves it. Be specific: what, and what it would let you do better.',
            ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string', 'description' => 'The improvement, in a short line.'],
                'why' => ['type' => 'string', 'description' => 'What it would let you do better, in one or two sentences.'],
                'size' => ['type' => 'string', 'enum' => ['Small', 'Medium', 'Large'], 'description' => 'Your best guess of the work.'],
            ], 'required' => ['title', 'why', 'size']],
            fn (): string => 'Filing a request to IT',
            function (array $in) use ($agent): string {
                $title = trim((string) ($in['title'] ?? ''));
                if ($title === '') {
                    return 'Not filed: the request had no title.';
                }
                if (Idea::whereRaw('lower(title) = ?', [mb_strtolower($title)])->exists()) {
                    return 'Already on the IT board.';
                }
                if (Idea::where('agent', $agent)->where('status', 'wait')->count() >= self::OPEN_REQUESTS_PER_AGENT) {
                    return 'Not filed: you already have '.self::OPEN_REQUESTS_PER_AGENT.' requests waiting for the owner.';
                }
                Idea::create(['agent' => $agent, 'title' => mb_substr($title, 0, 200), 'why' => mb_substr(trim((string) ($in['why'] ?? '')), 0, 1000),
                    'size' => in_array($in['size'] ?? null, ['Small', 'Medium', 'Large'], true) ? $in['size'] : 'Medium', 'status' => 'wait']);

                return 'Filed on the IT board; it waits for the owner\'s approval.';
            });
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
