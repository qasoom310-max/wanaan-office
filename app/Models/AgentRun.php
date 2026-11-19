<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One piece of work by an agent, step by step. The desks, the "Right now" strip and
 * the typing bubble show exactly these steps, so the motion on screen is real work.
 *
 * @property Carbon|null $claimed_at
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['agent', 'channel', 'owner_id', 'message_id', 'status', 'step', 'step_number', 'step_total', 'error', 'claimed_at', 'heartbeat_at', 'finished_at', 'input_tokens', 'output_tokens', 'meeting'])]
class AgentRun extends Model
{
    /** In a team meeting, queued behind the agents before it. */
    public const WAITING = 'waiting';

    public const WORKING = 'working';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meeting' => 'boolean', 'claimed_at' => 'datetime', 'heartbeat_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function moveTo(string $step, int $number, int $total): void
    {
        $this->update(['step' => $step, 'step_number' => $number, 'step_total' => $total, 'heartbeat_at' => now()]);
    }

    public function isStuck(): bool
    {
        return $this->status === self::WORKING
            && ($this->heartbeat_at ?? $this->created_at)->lt(now()->subSeconds(config('office.stuck_after_seconds')));
    }
}
