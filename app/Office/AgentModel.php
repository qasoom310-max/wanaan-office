<?php

namespace App\Office;

use App\Office\Tools\AgentTool;
use Closure;

/**
 * Whatever writes an agent's answer. The real one asks Claude; tests use a fake.
 */
interface AgentModel
{
    public function isConfigured(): bool;

    /**
     * @param  list<array{role: 'user'|'assistant'|'system', content: string|list<array<string, mixed>>}>  $messages
     * @param  list<AgentTool>  $tools  what the agent may use while answering
     * @param  Closure(string): void  $onStep  told each step as the agent takes it
     * @param  array{effort?: 'low'|'medium'|'high', max_rounds?: int, web_search?: bool}  $limits  how hard the agent may work, and whether it may search the web
     * @return array{text: string, input_tokens: int, output_tokens: int, cache_write_tokens?: int, cache_read_tokens?: int, web_searches?: int}
     *
     * @throws AgentProblem when no answer could be written
     */
    public function reply(string $instructions, array $messages, array $tools, Closure $onStep, array $limits = []): array;
}
