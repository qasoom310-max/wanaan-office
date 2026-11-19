<?php

namespace App\Office\Tools;

use App\Office\Seo\SeoClient;

/**
 * The whole SEO system as the marketing team reads it: what the websites rank for and
 * the plan to get there, the writing calendar and the articles, the site's sections and
 * every page, the posts and backlinks being followed, the numbers and how they changed,
 * the recommendations and the adviser's latest report. Marketing follows these decisions.
 */
class SeoTools
{
    public function __construct(private SeoClient $seo) {}

    /**
     * @return list<AgentTool>
     */
    public function all(): array
    {
        $project = ['type' => 'integer', 'description' => 'The project (website) id, from seo_projects.'];
        $byProject = fn (string $what): array => ['type' => 'object', 'properties' => ['project_id' => $project], 'required' => ['project_id']];
        $read = fn (string $path): \Closure => fn (array $in): string => $this->json($this->seo->get('projects/'.(int) $in['project_id'].'/'.$path));

        return [
            new AgentTool('seo_projects', 'The websites the SEO system works on, with the confirmed business facts to write from. Call this first.',
                ['type' => 'object', 'properties' => new \stdClass],
                fn (): string => 'Opening the SEO system: projects',
                fn (): string => $this->json($this->seo->get('projects'))),
            new AgentTool('seo_campaigns', 'The keywords a website is working to rank for: target position, the route chosen, the plan and how far it has got. Marketing content should support these.',
                $byProject('campaigns'), fn (): string => 'Opening the SEO system: keywords and plans', $read('campaigns')),
            new AgentTool('seo_content', 'The writing calendar: every planned, written or published piece, its keyword, section, date and whether it is late.',
                $byProject('content'), fn (): string => 'Opening the SEO system: the writing calendar', $read('content')),
            new AgentTool('seo_content_item', 'One written piece in full (title, description, article, image prompt), to turn into posts, captions, pictures or a video script.',
                ['type' => 'object', 'properties' => ['project_id' => $project, 'content_id' => ['type' => 'integer', 'description' => 'The piece id, from seo_content.']], 'required' => ['project_id', 'content_id']],
                fn (): string => 'Opening the SEO system: an article',
                fn (array $in): string => $this->json($this->seo->get('projects/'.(int) $in['project_id'].'/content/'.(int) $in['content_id']))),
            new AgentTool('seo_structure', 'The website\'s sections and main pages, and the page each one should send visitors to. Use it to pick the link for a post.',
                $byProject('structure'), fn (): string => 'Opening the SEO system: site sections', $read('structure')),
            new AgentTool('seo_numbers', 'The newest numbers of each kind for a website: search, overview, checks.',
                $byProject('numbers'), fn (): string => 'Opening the SEO system: the numbers', $read('numbers')),
            new AgentTool('seo_pages', 'Every page of a website the crawler found: title, main heading, outline, status (working, redirect, error), no-index, description, word count, images without alt text, schema and speed. Use it to check a page before linking to it or writing about it.',
                $byProject('pages'), fn (): string => 'Opening the SEO system: the website\'s pages',
                fn (array $in): string => $this->json($this->allPages((int) $in['project_id']))),
            new AgentTool('seo_links', 'The blog posts and backlinks being followed: where they are, whether they still work and point to our page, and their 14/30/45/60-day reviews (indexed, clicks, impressions, position, top searches).',
                $byProject('links'), fn (): string => 'Opening the SEO system: posts and backlinks', $read('links')),
            new AgentTool('seo_history', 'How one kind of number changed over time, newest first. kind is search, overview, checks or campaign:<campaign id>.',
                ['type' => 'object', 'properties' => ['project_id' => $project, 'kind' => ['type' => 'string', 'description' => 'search, overview, checks or campaign:<id> (default search).']], 'required' => ['project_id']],
                fn (): string => 'Opening the SEO system: how the numbers changed',
                fn (array $in): string => $this->json($this->seo->get('projects/'.(int) $in['project_id'].'/history', ['kind' => $in['kind'] ?? 'search', 'limit' => 60]))),
            new AgentTool('seo_recommendations', 'The SEO system\'s Recommendations page: what to do next for a website, most important first, who it is for and how much work it is.',
                $byProject('recommendations'), fn (): string => 'Opening the SEO system: recommendations', $read('recommendations')),
            new AgentTool('seo_advice', 'The SEO adviser\'s most recent report for a website, including Google updates that matter.',
                $byProject('advice'), fn (): string => 'Opening the SEO system: the adviser\'s report', $read('advice')),
        ];
    }

    /**
     * Every page of a website, page by page, up to a limit so one answer stays readable.
     *
     * @return array{rows: list<mixed>, count: int, complete: bool}
     */
    private function allPages(int $project): array
    {
        $rows = [];
        $after = 0;
        do {
            $page = $this->seo->get('projects/'.$project.'/pages', ['after_id' => $after, 'limit' => 500]);
            array_push($rows, ...($page['data'] ?? []));
            $after = $page['next_after_id'] ?? null;
        } while ($after !== null && count($rows) < 1500);

        return ['rows' => array_slice($rows, 0, 1500), 'count' => min(count($rows), 1500), 'complete' => $after === null];
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
