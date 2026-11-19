<?php

namespace App\Office\Tools;

use App\Models\AgentRun;
use App\Models\Fact;
use App\Models\Idea;
use App\Models\Message;
use App\Models\MessageFile;
use App\Models\MonthlyReport;
use App\Models\PlanPage;
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
     * A manager writes (or revises) its improvement report for the month: one per month.
     */
    public function monthlyReport(string $agent): AgentTool
    {
        return new AgentTool('office_monthly_report', 'Save your improvement report for a month (this month, or last month when it has just ended). One report per month: saving again replaces it. Write it in Markdown with headings: Summary · What went well (with numbers) · What is wrong (with numbers and examples) · What I will improve next month · What I need from the owner · What I need from IT · Risks. Base every figure on the real data you read, name the period, and keep assumptions in a "To confirm" list.',
            ['type' => 'object', 'properties' => [
                'month' => ['type' => 'string', 'description' => 'YYYY-MM: this month or last month.'],
                'title' => ['type' => 'string', 'description' => 'e.g. "Limousine: improvement report, October 2026".'],
                'report' => ['type' => 'string', 'description' => 'The whole report in Markdown.'],
            ], 'required' => ['month', 'title', 'report']],
            fn (): string => 'Saving the monthly improvement report',
            function (array $in) use ($agent): string {
                $month = (string) ($in['month'] ?? '');
                $allowed = [now()->format('Y-m'), now()->subMonthNoOverflow()->format('Y-m')];
                if (! in_array($month, $allowed, true)) {
                    return 'Not saved: the month must be '.implode(' or ', $allowed).'.';
                }
                $body = trim((string) ($in['report'] ?? ''));
                if (mb_strlen($body) < 200) {
                    return 'Not saved: a monthly report must be a full report, not a note.';
                }
                $report = MonthlyReport::updateOrCreate(['agent' => $agent, 'month' => $month], ['title' => mb_substr(trim((string) $in['title']), 0, 200), 'body' => mb_substr($body, 0, 200000)]);

                return 'Saved as "'.$report->title.'" on the Monthly reports page.';
            });
    }

    /**
     * The website plan: read it, and add or change pages. A changed approved page goes
     * back to "Planned" so the owner approves it again as it now is.
     *
     * @return list<AgentTool>
     */
    public function websitePlan(): array
    {
        return [
            new AgentTool('office_plan_list', 'The website plan as it stands: every page with its status (Planned, Changes asked, Approved, Being built, Built, Live) and the owner\'s notes.',
                ['type' => 'object', 'properties' => new \stdClass],
                fn (): string => 'Opening the website plan',
                fn (): string => $this->json(PlanPage::orderBy('sort')->orderBy('id')->get()
                    ->map(fn (PlanPage $page): array => ['id' => $page->id, 'title' => $page->title, 'path' => $page->path, 'purpose' => $page->purpose, 'audience' => $page->audience, 'content' => $page->content, 'status' => $page->statusName(), 'owner_note' => $page->owner_note, 'order' => $page->sort])->all())),
            new AgentTool('office_plan_save', 'Add a page to the website plan, or change one (give its id). Describe everything the page shows, section by section, and every rule it follows. Changing an approved page sends it back to the owner for approval.',
                ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'The page to change; leave out to add a new page.'],
                    'title' => ['type' => 'string', 'description' => 'The page name, e.g. "Book a ride".'],
                    'path' => ['type' => 'string', 'description' => 'Its address, e.g. /book.'],
                    'purpose' => ['type' => 'string', 'description' => 'Why the page exists, in one or two sentences.'],
                    'audience' => ['type' => 'string', 'description' => 'Who uses it.'],
                    'content' => ['type' => 'string', 'description' => 'Everything on the page, section by section: texts, fields, buttons, rules, languages, what happens next.'],
                    'order' => ['type' => 'integer', 'description' => 'Its place in the plan (1 = first).'],
                ], 'required' => ['title', 'purpose', 'content']],
                fn (array $in): string => isset($in['id']) ? 'Updating a page of the website plan' : 'Adding a page to the website plan',
                function (array $in): string {
                    $fields = [
                        'title' => mb_substr(trim((string) $in['title']), 0, 150),
                        'path' => isset($in['path']) ? mb_substr(trim((string) $in['path']), 0, 150) : null,
                        'purpose' => mb_substr(trim((string) $in['purpose']), 0, 2000),
                        'audience' => isset($in['audience']) ? mb_substr(trim((string) $in['audience']), 0, 150) : null,
                        'content' => mb_substr(trim((string) $in['content']), 0, 20000),
                        'sort' => (int) ($in['order'] ?? 0),
                    ];

                    if (isset($in['id'])) {
                        $page = PlanPage::find((int) $in['id']);
                        if ($page === null) {
                            return 'No page with that id.';
                        }
                        if (in_array($page->status, ['building', 'built', 'live'], true)) {
                            return 'Not changed: "'.$page->title.'" is already '.mb_strtolower($page->statusName()).'. Ask the owner in the chat before changing it.';
                        }
                        $page->update([...$fields, 'status' => 'planned', 'approved_at' => null]);

                        return 'Updated "'.$page->title.'"; it waits for the owner\'s approval.';
                    }

                    if (PlanPage::get()->contains(fn (PlanPage $page): bool => self::sameQuestion($page->title, $fields['title']))) {
                        return 'Not added: a page like "'.$fields['title'].'" is already in the plan. Change that one instead (give its id).';
                    }
                    PlanPage::create([...$fields, 'status' => 'planned']);

                    return 'Added "'.$fields['title'].'" to the website plan; it waits for the owner\'s approval.';
                }),
        ];
    }

    /**
     * Open a file attached earlier in this same conversation (never another chat's).
     */
    public function openFile(string $channel, ?int $ownerId): AgentTool
    {
        return new AgentTool('office_open_file', 'Open a file attached earlier in this conversation (a picture, PDF, spreadsheet or text file), by its file number.',
            ['type' => 'object', 'properties' => ['file_id' => ['type' => 'integer', 'description' => 'The file number, as shown next to "Attached earlier".']], 'required' => ['file_id']],
            fn (array $in): string => 'Opening an attached file',
            function (array $in) use ($channel, $ownerId): string|array {
                $file = MessageFile::with('message')->find((int) ($in['file_id'] ?? 0));
                $message = $file?->message;
                if ($file === null || $message->channel !== $channel || $message->owner_id !== $ownerId) {
                    return 'No such file in this conversation.';
                }

                return $file->contentBlocks();
            });
    }

    /**
     * Ask the owner something only the owner can answer. It waits on the Questions page.
     */
    public function askOwner(string $agent): AgentTool
    {
        $limit = $this->spending->isManager($agent) ? 'As a manager you may ask as many as your work needs, one question each.' : 'At most '.Question::OPEN_PER_AGENT.' open at a time.';

        return new AgentTool('office_ask_owner', 'Put a question on the owner\'s Questions page, when only the owner can give the answer and your work depends on it. Their answer becomes a fact you will always read. '.$limit,
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

                // Never ask the owner twice: compare with every question ever asked
                // (by any agent, answered or not) and every confirmed fact.
                foreach (Question::orderBy('id')->get() as $earlier) {
                    if (! self::sameQuestion($question, $earlier->question)) {
                        continue;
                    }
                    if (! $earlier->isOpen()) {
                        return 'Not added: the owner already answered this. Their answer: "'.$earlier->answer.'". Use it and do not ask again.';
                    }

                    return 'Not added: it is already on the Questions page, waiting for the owner ('.$this->team->title($earlier->agent).' asked it).';
                }
                foreach (Fact::orderBy('id')->get() as $fact) {
                    if (self::sameQuestion($question, $fact->text)) {
                        return 'Not added: a confirmed fact already answers this: "'.$fact->text.'". Use it and do not ask again.';
                    }
                }

                // Managers may ask as much as they need; employees keep a few open at a time.
                if (! $this->spending->isManager($agent) && Question::where('agent', $agent)->whereNull('answered_at')->count() >= Question::OPEN_PER_AGENT) {
                    return 'Not added: you already have '.Question::OPEN_PER_AGENT.' open questions. Wait for answers first.';
                }
                Question::create(['agent' => $agent, 'question' => mb_substr($question, 0, 500), 'why' => mb_substr(trim((string) ($in['why'] ?? '')), 0, 500)]);

                return 'Added to the owner\'s Questions page.';
            });
    }

    /**
     * True when two questions (or two requests) mean the same thing in other words: most
     * of the meaningful words of the shorter one appear in the other (English or Arabic).
     */
    public static function sameQuestion(string $a, string $b): bool
    {
        $words = function (string $text): array {
            $text = mb_strtolower($text);
            $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';
            $stop = ['the', 'and', 'for', 'are', 'what', 'which', 'how', 'many', 'much', 'can', 'does', 'did', 'you', 'our', 'your', 'with', 'from', 'that', 'this', 'there', 'their', 'have', 'has', 'should', 'would', 'will', 'into', 'before', 'after', 'about', 'every', 'each', 'any', 'who', 'when', 'where', 'why', 'not', 'yet', 'please', 'know', 'want', 'need'];

            return array_values(array_unique(array_filter(explode(' ', $text), fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, $stop, true))));
        };

        $x = $words($a);
        $y = $words($b);
        if ($x === [] || $y === []) {
            return false;
        }

        $shared = count(array_intersect($x, $y));

        return $shared / min(count($x), count($y)) >= 0.7;
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
                // Never bring the owner the same request twice: compare by meaning with
                // every request ever filed, whatever the owner decided.
                foreach (Idea::orderBy('id')->get() as $earlier) {
                    if (! self::sameQuestion($title.' '.($in['why'] ?? ''), $earlier->title.' '.$earlier->why) && ! self::sameQuestion($title, $earlier->title)) {
                        continue;
                    }

                    return match ($earlier->status) {
                        'yes', 'ready' => 'Not filed: the owner already approved this ("'.$earlier->title.'"). It is waiting to be built; do not ask again.',
                        'building' => 'Not filed: the owner already approved this and it is being built ("'.$earlier->title.'").',
                        'done' => 'Not filed: this is already done ("'.$earlier->title.'").',
                        'later' => 'Not filed: the owner put this off ("'.$earlier->title.'"). Do not ask again unless something important has changed; if it has, say what in the chat instead.',
                        default => 'Not filed: it is already on the IT board, waiting for the owner ("'.$earlier->title.'").',
                    };
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
