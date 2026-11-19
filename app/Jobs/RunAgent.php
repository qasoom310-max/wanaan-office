<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\Message;
use App\Models\MessageRead;
use App\Office\AgentModel;
use App\Office\AgentProblem;
use App\Office\Instructions;
use App\Office\Spending;
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

    public function handle(AgentModel $model, Instructions $instructions, Team $team, Spending $spending): void
    {
        // Claimed once: a repeated or duplicated job finds the run taken and does nothing.
        if (AgentRun::whereKey($this->run->id)->whereNull('claimed_at')->update(['claimed_at' => now()]) === 0) {
            return;
        }

        set_time_limit($this->timeout);
        $run = $this->run->refresh();
        $run->update(['status' => AgentRun::WORKING]);
        $hasData = $team->hasData($run->agent);
        $total = $hasData ? 4 : 3;

        try {
            $run->moveTo('Reading your message', 1, $total);

            $run->moveTo('Reading the facts and the conversation', 2, $total);
            $system = $instructions->office();
            $conversation = $instructions->conversation($run->channel, $run->owner_id);
            // The agent's own part goes last, after the shared (cached) conversation.
            $conversation[] = ['role' => 'system', 'content' => $instructions->agent($run->agent, $run->channel, $run->meeting, $spending->modeFor($run->agent))];

            // Spending rules: everyone speaks less near the budget, employees stop at it,
            // managers may use the reserve above it only when needed, nobody goes past it.
            $budgetMode = $spending->modeFor($run->agent);
            if ($budgetMode === Spending::STOPPED) {
                if ($run->meeting && $run->agent !== 'sec') {
                    $this->markRead();
                    $run->update(['status' => AgentRun::DONE, 'step' => null, 'finished_at' => now()]);

                    return;
                }

                $reserve = $spending->used() < 1 + config('office.manager_reserve');
                $this->answer('This month\'s AI budget ($'.number_format((float) $spending->budget(), 2).') is used up, so I can\'t think until the owner raises it in Settings.'
                    .($reserve ? ' Managers may still answer what cannot wait, from their reserve.' : '').' Your message is saved.');

                return;
            }

            if (! $model->isConfigured()) {
                // One notice per meeting is enough; the others still show they read it.
                if ($run->meeting && $run->agent !== 'sec') {
                    $this->markRead();
                    $run->update(['status' => AgentRun::DONE, 'step' => null, 'finished_at' => now()]);

                    return;
                }

                $this->answer("I can't think yet: the AI key isn't connected. Your message is saved, and I'll answer once the owner adds the key in Settings.");

                return;
            }

            $step = 3;
            $run->moveTo('Thinking', 3, $total);
            $limits = match ($budgetMode) {
                Spending::TIGHT => ['effort' => 'low', 'max_rounds' => 6],
                Spending::RESERVE => ['effort' => 'low', 'max_rounds' => 3],
                default => [],
            };
            $reply = $model->reply($system, $conversation, $team->toolsFor($run->agent), function (string $label) use ($run, &$step, &$total): void {
                // Each tool the agent uses is a real step on its desk.
                $step = min($step + 1, 250);
                $total = max($total, $step + 1);
                $run->moveTo($label, $step, $total);
            }, $limits);

            $run->update([
                'input_tokens' => $reply['input_tokens'],
                'output_tokens' => $reply['output_tokens'],
                'cache_write_tokens' => $reply['cache_write_tokens'] ?? 0,
                'cache_read_tokens' => $reply['cache_read_tokens'] ?? 0,
            ]);

            // In a meeting, an agent with nothing to add only marks the message read.
            if ($run->meeting && self::isJustRead($reply['text'])) {
                $this->markRead();
                $run->update(['status' => AgentRun::DONE, 'step' => null, 'finished_at' => now()]);

                return;
            }

            $this->answer($reply['text']);
        } catch (AgentProblem $problem) {
            $this->giveUp($problem->getMessage());
        } catch (Throwable $exception) {
            Log::error('Agent run failed', ['run' => $run->id, 'error' => $exception->getMessage()]);
            $this->giveUp('something went wrong on our side');
        }
    }

    public const READ_ONLY_REPLY = 'READ';

    public static function isJustRead(string $text): bool
    {
        return strtoupper(trim($text, " \n\r\t.!")) === self::READ_ONLY_REPLY;
    }

    private function markRead(): void
    {
        if ($this->run->message_id !== null) {
            MessageRead::mark($this->run->message_id, $this->run->agent);
        }
    }

    private function answer(string $text): void
    {
        $this->markRead();

        Message::create([
            'channel' => $this->run->channel,
            'owner_id' => $this->run->owner_id,
            'agent' => $this->run->agent,
            'body' => $text,
        ]);

        $this->run->update(['status' => AgentRun::DONE, 'step' => null, 'finished_at' => now()]);
    }

    /**
     * Start turns nobody picked up (the web request that should have run them was cut
     * off), oldest first, so a meeting carries on where it stopped.
     */
    public static function resumeUnclaimed(): int
    {
        $started = 0;
        AgentRun::whereNull('claimed_at')
            ->whereIn('status', [AgentRun::WAITING, AgentRun::WORKING])
            ->where('created_at', '<', now()->subMinute())
            ->orderBy('id')
            ->each(function (AgentRun $run) use (&$started): void {
                app()->call([new self($run), 'handle']);
                $started++;
            });

        return $started;
    }

    /**
     * Close a run that stopped moving (the server restarted, the request was killed),
     * so the owner gets an answer instead of waiting forever.
     */
    public static function closeAbandoned(): int
    {
        $closed = 0;
        AgentRun::where(fn ($query) => $query
            ->where(fn ($query) => $query->where('status', AgentRun::WORKING)
                ->where(fn ($query) => $query->where('heartbeat_at', '<', now()->subMinutes(10))->orWhere(fn ($query) => $query->whereNull('heartbeat_at')->where('created_at', '<', now()->subMinutes(10)))))
            // A turn in a meeting may wait behind eleven others, so it gets longer.
            ->orWhere(fn ($query) => $query->where('status', AgentRun::WAITING)->where('created_at', '<', now()->subMinutes(30))))
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
