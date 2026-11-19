<?php

namespace Tests\Fakes;

use App\Office\AgentModel;
use App\Office\AgentProblem;
use App\Office\Tools\AgentTool;
use Closure;

/**
 * Stands in for Claude: optionally uses some tools first (like Claude would), then
 * answers with a fixed text, remembering everything it was told and read.
 */
class FakeAgentModel implements AgentModel
{
    public string $lastInstructions = '';

    /** @var list<array{role: string, content: string}> */
    public array $lastMessages = [];

    public int $calls = 0;

    /** @var (callable(string, list<array{role: string, content: string}>): string)|null */
    public $answerWith = null;

    /** @var list<list<array{role: string, content: string}>> */
    public array $conversations = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> Tools to use before answering: [name, input]. */
    public array $useTools = [];

    /** @var list<string> Names of the tools offered on the last call. */
    public array $offeredTools = [];

    /** @var array<string, string> What each used tool returned. */
    public array $toolResults = [];

    /** @var list<string> Steps reported, in order. */
    public array $steps = [];

    public function __construct(public bool $configured = true, public ?string $problem = null, public string $answer = 'Noted.') {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /** @var list<array<string, mixed>> The messages exactly as sent on the last call. */
    public array $rawMessages = [];

    /** @var list<string> The shared system part of every call. */
    public array $systems = [];

    /** @var array<string, mixed> Limits given on the last call. */
    public array $lastLimits = [];

    public function reply(string $instructions, array $messages, array $tools, Closure $onStep, array $limits = []): array
    {
        $this->lastLimits = $limits;
        $this->calls++;
        // Flatten for easy checking: the shared rules plus the agent's own system part,
        // and the conversation as plain text turns.
        $flat = [];
        $own = '';
        foreach ($messages as $message) {
            $text = is_string($message['content']) ? $message['content'] : implode("\n", array_column($message['content'], 'text'));
            if ($message['role'] === 'system') {
                $own .= "\n".$text;

                continue;
            }
            $flat[] = ['role' => $message['role'], 'content' => $text];
        }
        $this->lastInstructions = $instructions.$own;
        $this->lastMessages = $flat;
        $this->conversations[] = $flat;
        $this->rawMessages = $messages;
        $this->systems[] = $instructions;
        $this->offeredTools = array_map(fn (AgentTool $tool): string => $tool->name, $tools);

        foreach ($this->useTools as [$name, $input]) {
            foreach ($tools as $tool) {
                if ($tool->name === $name) {
                    $label = ($tool->step)($input);
                    $this->steps[] = $label;
                    $onStep($label);
                    $this->toolResults[$name] = ($tool->run)($input);
                }
            }
        }

        if ($this->problem !== null) {
            throw new AgentProblem($this->problem);
        }

        $text = $this->answerWith !== null ? ($this->answerWith)($this->lastInstructions, $messages) : $this->answer;

        return ['text' => $text, 'input_tokens' => 120, 'output_tokens' => 8];
    }
}
