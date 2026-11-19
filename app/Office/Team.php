<?php

namespace App\Office;

use App\Models\AgentName;
use App\Office\Erp\ErpClient;
use App\Office\Tools\AgentTool;
use App\Office\Tools\ErpTools;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The agents in the office, from config/office.php, with the names the owner gave them.
 */
class Team
{
    /** @var array<string, string>|null */
    private ?array $names = null;

    /**
     * @return array<string, array{title: string, team: string, role: string, phase: int, data: list<string>, brief: string}>
     */
    public function all(): array
    {
        return config('office.agents');
    }

    public function exists(string $agent): bool
    {
        return array_key_exists($agent, $this->all());
    }

    /**
     * @return array{title: string, team: string, role: string, phase: int, data: list<string>, brief: string}
     */
    public function get(string $agent): array
    {
        return $this->all()[$agent] ?? throw new InvalidArgumentException("No agent {$agent}.");
    }

    public function title(string $agent): string
    {
        return $this->get($agent)['title'];
    }

    public function nameOf(string $agent): ?string
    {
        $this->names ??= AgentName::pluck('name', 'agent')->all();

        return $this->names[$agent] ?? null;
    }

    /** "Noura" or, before it is named, "Secretary". */
    public function displayName(string $agent): string
    {
        return $this->nameOf($agent) ?? $this->title($agent);
    }

    /** "Noura · Secretary" or "Secretary". */
    public function label(string $agent): string
    {
        $name = $this->nameOf($agent);

        return $name !== null ? "{$name} · {$this->title($agent)}" : $this->title($agent);
    }

    /** True when the agent can read real data right now; false means it may only talk. */
    public function hasData(string $agent): bool
    {
        return $this->connectedSources($agent) !== [];
    }

    /**
     * The agent's data sources that are connected right now.
     *
     * @return list<string>
     */
    public function connectedSources(string $agent): array
    {
        return array_values(array_filter($this->get($agent)['data'], fn (string $source): bool => match ($source) {
            'erp' => app(ErpClient::class)->isConnected(),
            default => false,
        }));
    }

    /**
     * What the agent may use while answering.
     *
     * @return list<AgentTool>
     */
    public function toolsFor(string $agent): array
    {
        return in_array('erp', $this->connectedSources($agent), true) ? app(ErpTools::class)->all() : [];
    }

    /**
     * Agents grouped by team, in config order.
     *
     * @return Collection<string, list<string>>
     */
    public function byTeam(): Collection
    {
        return collect(array_keys($this->all()))->groupBy(fn (string $agent): string => $this->get($agent)['team'])
            ->map(fn (Collection $agents): array => $agents->values()->all());
    }

    /**
     * The agent a name or title points to, from an @mention in a message.
     */
    public function mentionedIn(string $text): ?string
    {
        $lower = mb_strtolower($text);

        foreach (array_keys($this->all()) as $agent) {
            foreach (array_filter([$this->nameOf($agent), $this->title($agent)]) as $label) {
                if (str_contains($lower, '@'.mb_strtolower($label))) {
                    return $agent;
                }
            }
        }

        return null;
    }

    public function forgetNames(): void
    {
        $this->names = null;
    }
}
