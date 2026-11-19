<?php

namespace App\Office\Tools;

use App\Models\AgentRun;
use App\Models\Message;
use App\Office\AgentModel;
use App\Office\AgentProblem;
use App\Office\Instructions;
use App\Office\Spending;
use App\Office\Team;
use Throwable;

/**
 * One agent asks another: the colleague answers with its own data and tools (the Gulf
 * Planner searches the web, the Limousine Manager reads the trips…), its answer is shown
 * in the same chat so the owner can follow it, and it comes back to the one who asked.
 * A colleague answers alone: it cannot pass the question on, so there are no chains.
 */
class ColleagueTool
{
    public function __construct(
        private Team $team,
        private Instructions $instructions,
        private Spending $spending,
        private AgentModel $model,
    ) {}

    public function for(string $asker, string $channel, ?int $ownerId): AgentTool
    {
        $colleagues = array_values(array_filter(array_keys($this->team->all()), fn (string $agent): bool => $agent !== $asker));
        $list = implode('; ', array_map(fn (string $agent): string => $agent.' = '.$this->team->label($agent).' ('.$this->team->get($agent)['role'].')', $colleagues));

        return new AgentTool('office_ask_colleague', 'Ask a colleague for what is their job, and get their answer back. For research on the web, the Gulf or competitors ask the Gulf Planner (pln) or the Trend Researcher (res). Colleagues: '.$list.'. Ask one clear question; their answer is shown to the owner in this chat.',
            ['type' => 'object', 'properties' => [
                'colleague' => ['type' => 'string', 'enum' => $colleagues, 'description' => 'Who to ask.'],
                'question' => ['type' => 'string', 'description' => 'The question, complete enough to answer without this conversation.'],
            ], 'required' => ['colleague', 'question']],
            fn (array $in): string => 'Asking '.$this->team->displayName((string) ($in['colleague'] ?? '')),
            fn (array $in): string => $this->ask($asker, (string) ($in['colleague'] ?? ''), trim((string) ($in['question'] ?? '')), $channel, $ownerId));
    }

    private function ask(string $asker, string $colleague, string $question, string $channel, ?int $ownerId): string
    {
        if (! $this->team->exists($colleague) || $colleague === $asker || $question === '') {
            return 'Ask an existing colleague a clear question.';
        }
        $budget = $this->spending->modeFor($colleague);
        if ($budget === Spending::STOPPED) {
            return $this->team->displayName($colleague).' cannot answer: this month\'s AI budget is used up for them.';
        }

        // The colleague's work shows on its own desk, and counts in its own spending.
        $run = AgentRun::create([
            'agent' => $colleague, 'channel' => $channel, 'owner_id' => $ownerId, 'status' => AgentRun::WORKING,
            'step' => 'Answering '.$this->team->displayName($asker), 'step_number' => 1, 'step_total' => 3,
            'claimed_at' => now(), 'heartbeat_at' => now(),
        ]);

        $own = $this->instructions->agent($colleague, $channel, false, $budget)
            ."\nA colleague, ".$this->team->label($asker).', is asking you something for their work. Answer them directly and briefly, from facts and your own data and tools, naming your sources. Say plainly what you do not know.';

        try {
            $reply = $this->model->reply(
                $this->instructions->office(),
                [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => '['.$this->team->label($asker).' asks you] '.$question]]],
                    ['role' => 'system', 'content' => $own],
                ],
                $this->team->toolsFor($colleague, $channel, $ownerId),
                function (string $label) use ($run): void {
                    $run->moveTo($label, min($run->step_number + 1, 250), max($run->step_total, $run->step_number + 2));
                },
                array_filter(['web_search' => $this->team->searchesWeb($colleague) ?: null, 'effort' => $budget === Spending::NORMAL ? null : 'low', 'max_rounds' => 6]),
            );
        } catch (AgentProblem $problem) {
            $run->update(['status' => AgentRun::FAILED, 'error' => $problem->getMessage(), 'finished_at' => now()]);

            return $this->team->displayName($colleague).' could not answer: '.$problem->getMessage().'.';
        } catch (Throwable) {
            $run->update(['status' => AgentRun::FAILED, 'error' => 'failed', 'finished_at' => now()]);

            return $this->team->displayName($colleague).' could not answer.';
        }

        $run->update([
            'status' => AgentRun::DONE, 'step' => null, 'finished_at' => now(),
            'input_tokens' => $reply['input_tokens'], 'output_tokens' => $reply['output_tokens'],
            'cache_write_tokens' => $reply['cache_write_tokens'] ?? 0, 'cache_read_tokens' => $reply['cache_read_tokens'] ?? 0,
            'web_searches' => $reply['web_searches'] ?? 0,
        ]);

        // Shown in the same chat, so the owner sees the team talking and can argue.
        Message::create([
            'channel' => $channel,
            'owner_id' => $ownerId,
            'agent' => $colleague,
            'body' => 'Asked by '.$this->team->displayName($asker).': '.$question."\n\n".$reply['text'],
        ]);

        return $reply['text'];
    }
}
