<?php

namespace App\Office;

/**
 * Whatever writes an agent's answer. The real one asks Claude; tests use a fake.
 */
interface AgentModel
{
    public function isConfigured(): bool;

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @return array{text: string, input_tokens: int, output_tokens: int}
     *
     * @throws AgentProblem when no answer could be written
     */
    public function reply(string $instructions, array $messages): array;
}
