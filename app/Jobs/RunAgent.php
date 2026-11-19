<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\Message;
use App\Office\AgentModel;
use App\Office\AgentProblem;
use App\Office\Instructions;
use App\Office\Team;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An agent answers a message. Each step is recorded on the run as it happens, which
 * is what the office shows while the agent works.
 */
class RunAgent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public AgentRun $run) {}

    public function handle(AgentModel $model, Instructions $instructions, Team $team): void
    {
        // Claimed once: a repeated or duplicated job finds the run taken and does nothing.
        if (AgentRun::whereKey($this->run->id)->whereNull('claimed_at')->update(['claimed_at' => now()]) === 0) {
            return;
        }

        set_time_limit($this->timeout);
        $run = $this->run->refresh();
        $hasData = $team->hasData($run->agent);
        $total = $hasData ? 4 : 3;

        try {
            $run->moveTo('Reading your message', 1, $total);

            $run->moveTo('Reading the facts and the conversation', 2, $total);
            $system = $instructions->for($run->agent, $run->channel);
            $conversation = $instructions->conversation($run->agent, $run->channel, $run->owner_id);

            if (! $model->isConfigured()) {
                $this->answer("I can't think yet: the AI key isn't connected. Your message is saved, and I'll answer once the owner adds the key in Settings.");

                return;
            }

            if ($hasData) {
                $run->moveTo('Looking at the data I can see', 3, $total);
            }

            $run->moveTo('Writing the answer', $total, $total);
            $reply = $model->reply($system, $conversation);

            $run->update(['input_tokens' => $reply['input_tokens'], 'output_tokens' => $reply['output_tokens']]);
            $this->answer($reply['text']);
        } catch (AgentProblem $problem) {
            $this->giveUp($problem->getMessage());
        } catch (Throwable $exception) {
            Log::error('Agent run failed', ['run' => $run->id, 'error' => $exception->getMessage()]);
            $this->giveUp('something went wrong on our side');
        }
    }

    private function answer(string $text): void
    {
        Message::create([
            'channel' => $this->run->channel,
            'owner_id' => $this->run->owner_id,
            'agent' => $this->run->agent,
            'body' => $text,
        ]);

        $this->run->update(['status' => AgentRun::DONE, 'step' => null, 'finished_at' => now()]);
    }

    /**
     * Close a run that stopped moving (the server restarted, the request was killed),
     * so the owner gets an answer instead of waiting forever.
     */
    public static function closeAbandoned(): int
    {
        $closed = 0;
        AgentRun::where('status', AgentRun::WORKING)
            ->where(fn ($query) => $query->where('heartbeat_at', '<', now()->subMinutes(10))->orWhere(fn ($query) => $query->whereNull('heartbeat_at')->where('created_at', '<', now()->subMinutes(10))))
            ->each(function (AgentRun $run) use (&$closed): void {
                (new self($run))->giveUp('I stopped before finishing. Please send your message again');
                $closed++;
            });

        return $closed;
    }

    private function giveUp(string $reason): void
    {
        Message::create([
            'channel' => $this->run->channel,
            'owner_id' => $this->run->owner_id,
            'agent' => $this->run->agent,
            'body' => "I couldn't answer: {$reason}. Your message is saved.",
        ]);

        $this->run->update(['status' => AgentRun::FAILED, 'error' => $reason, 'finished_at' => now()]);
    }
}
