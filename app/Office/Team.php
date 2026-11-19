<?php

namespace App\Office;

use App\Models\AgentName;
use App\Office\Erp\ErpClient;
use App\Office\Seo\SeoClient;
use App\Office\Tools\AgentTool;
use App\Office\Tools\ErpTools;
use App\Office\Tools\OfficeTools;
use App\Office\Tools\SeoTools;
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
     * @return array<string, array{title: string, team: string, role: string, phase: int, data: list<string>, erp?: list<string>, manager?: bool, brief: string}>
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
            'seo' => app(SeoClient::class)->isConnected(),
            'web', 'office' => true,
            default => false,
        }));
    }

    /**
     * What the agent may use while answering: its data sources, plus asking the owner
     * (everyone) and asking IT (managers and IT).
     *
     * @return list<AgentTool>
     */
    public function toolsFor(string $agent, ?string $channel = null, ?int $ownerId = null): array
    {
        $sources = $this->connectedSources($agent);
        $office = app(OfficeTools::class);
        $tools = [];

        if (in_array('erp', $sources, true)) {
            array_push($tools, ...app(ErpTools::class)->forSections($this->get($agent)['erp'] ?? []));
        }
        if (in_array('seo', $sources, true)) {
            array_push($tools, ...app(SeoTools::class)->all());
        }
        if (in_array('office', $sources, true)) {
            array_push($tools, ...$office->reading());
        }
        if ($agent === 'it') {
            array_push($tools, ...$office->websitePlan());
            // The one agent that may write into the SEO system, and only into the new website's project.
            if (in_array('seo', $sources, true)) {
                array_push($tools, ...app(SeoTools::class)->writing());
            }
        }

        // Every agent may open files from its own conversation and ask the owner;
        // managers and IT may also ask IT for improvements.
        if ($channel !== null) {
            $tools[] = $office->openFile($channel, $ownerId);
        }
        $tools[] = $office->askOwner($agent);
        if (($this->get($agent)['manager'] ?? false) || $agent === 'it') {
            $tools[] = $office->requestIt($agent);
        }
        if ($this->get($agent)['manager'] ?? false) {
            $tools[] = $office->monthlyReport($agent);
        }

        return $tools;
    }

    /** True when the agent may search the web while answering. */
    public function searchesWeb(string $agent): bool
    {
        return in_array('web', $this->connectedSources($agent), true);
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
