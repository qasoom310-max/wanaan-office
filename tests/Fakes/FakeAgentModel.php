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

    /** @var (callable(string, list<array{role: string, content: string}>): string)|null Picks the answer from what the agent was told. */
    public $answerWith = null;

    /** @var list<list<array{role: string, content: string}>> Every conversation sent, in order. */
    public array $conversations = [];

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
        $this->conversations[] = $messages;

        if ($this->problem !== null) {
            throw new AgentProblem($this->problem);
        }

        $text = $this->answerWith !== null ? ($this->answerWith)($instructions, $messages) : $this->answer;

        return ['text' => $text, 'input_tokens' => 120, 'output_tokens' => 8];
    }
}
