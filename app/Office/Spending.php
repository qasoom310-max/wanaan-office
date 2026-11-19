<?php

namespace App\Office;

use App\Models\AgentRun;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * What the agents have cost, worked out from the tokens each run used, and the owner's
 * monthly budget. These are estimates at list prices; the exact bill is in the
 * Anthropic console.
 */
class Spending
{
    public const BUDGET = 'monthly_budget_usd';

    /** Plenty left: work as usual. */
    public const NORMAL = 'normal';

    /** Near the budget: speak less, look things up only when needed. */
    public const TIGHT = 'tight';

    /** Over the budget, a manager on the reserve: only what is really needed, very short. */
    public const RESERVE = 'reserve';

    /** No more spending for this agent until the owner raises the budget. */
    public const STOPPED = 'stopped';

    public function costOf(int $inputTokens, int $outputTokens): float
    {
        [$in, $out] = config('office.prices')[config('office.model')] ?? [0, 0];

        return ($inputTokens * $in + $outputTokens * $out) / 1_000_000;
    }

    public function since(Carbon $from): float
    {
        $tokens = AgentRun::where('created_at', '>=', $from)
            ->selectRaw('coalesce(sum(input_tokens), 0) as input, coalesce(sum(output_tokens), 0) as output')->first();

        return $this->costOf((int) $tokens->input, (int) $tokens->output);
    }

    public function today(): float
    {
        return $this->since(now()->startOfDay());
    }

    public function thisMonth(): float
    {
        return $this->since(now()->startOfMonth());
    }

    /**
     * This month's cost per agent, most expensive first.
     *
     * @return array<string, float>
     */
    public function byAgentThisMonth(): array
    {
        return AgentRun::where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('agent, sum(input_tokens) as input, sum(output_tokens) as output')->groupBy('agent')->get()
            ->mapWithKeys(fn ($row): array => [$row->agent => $this->costOf((int) $row->input, (int) $row->output)])
            ->sortDesc()->all();
    }

    /** What a whole team meeting has cost on average, or null before the first one. */
    public function averageMeeting(): ?float
    {
        $meetings = AgentRun::where('meeting', true)->whereNotNull('finished_at')
            ->selectRaw('message_id, sum(input_tokens) as input, sum(output_tokens) as output')->groupBy('message_id')
            ->orderByDesc('message_id')->limit(20)->get();

        if ($meetings->isEmpty()) {
            return null;
        }

        return $meetings->avg(fn ($row): float => $this->costOf((int) $row->input, (int) $row->output));
    }

    public function budget(): ?float
    {
        $value = Setting::read(self::BUDGET);

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    public function isManager(string $agent): bool
    {
        return (bool) (config("office.agents.{$agent}.manager") ?? false);
    }

    /**
     * How an agent may spend right now, from the share of the budget already used:
     * everyone speaks less near the budget; employees stop at it; managers may use the
     * reserve above it only when it is really needed; nobody goes past the reserve.
     */
    public function modeFor(string $agent): string
    {
        $used = $this->used();

        if ($used === null || $used < config('office.budget_warning')) {
            return self::NORMAL;
        }
        if ($used < 1) {
            return self::TIGHT;
        }
        if ($this->isManager($agent) && $used < 1 + config('office.manager_reserve')) {
            return self::RESERVE;
        }

        return self::STOPPED;
    }

    /** The most anyone may spend this month: the budget plus the managers' reserve. */
    public function ceiling(): ?float
    {
        $budget = $this->budget();

        return $budget === null ? null : $budget * (1 + config('office.manager_reserve'));
    }

    /** Share of the budget used, 0..1+, or null without a budget. */
    public function used(): ?float
    {
        $budget = $this->budget();

        return $budget === null ? null : $this->thisMonth() / $budget;
    }
}
