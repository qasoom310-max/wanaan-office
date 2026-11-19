<?php

namespace Tests\Fakes;

use App\Office\AgentModel;
use App\Office\AgentProblem;

/**
 * Stands in for Claude: answers with a fixed text and remembers what it was told.
 */
class FakeAgentModel implements AgentModel
{
    public string $lastInstructions = '';

    /** @var list<array{role: 'user'|'assistant', content: string}> */
    public array $lastMessages = [];

    public int $calls = 0;

    public function __construct(public bool $configured = true, public ?string $problem = null, public string $answer = 'Noted.') {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function reply(string $instructions, array $messages): array
    {
        $this->calls++;
        $this->lastInstructions = $instructions;
        $this->lastMessages = $messages;

        if ($this->problem !== null) {
            throw new AgentProblem($this->problem);
        }

        return ['text' => $this->answer, 'input_tokens' => 120, 'output_tokens' => 8];
    }
}
