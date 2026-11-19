<?php

namespace App\Office;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Office\Erp\ErpProblem;
use App\Office\Tools\AgentTool;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Claude for an agent's answer, running the tools it asks for (reading the ERP)
 * and reporting each one as a step, until it writes its answer.
 */
class ClaudeAgentModel implements AgentModel
{
    private const MAX_TOKENS = 16000;

    /** Tool rounds before the agent must answer with what it has. */
    private const MAX_ROUNDS = 12;

    /** Characters of one tool result passed back, so one answer cannot overflow. */
    private const MAX_RESULT = 120000;

    public function __construct(private ?string $apiKey) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    public function reply(string $instructions, array $messages, array $tools, Closure $onStep): array
    {
        /** @var array<string, AgentTool> $byName */
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }
        $definitions = array_map(fn (AgentTool $tool): array => $tool->definition(), $tools);

        $inputTokens = 0;
        $outputTokens = 0;

        for ($round = 0; $round <= self::MAX_ROUNDS; $round++) {
            $response = $this->send($instructions, $messages, $round < self::MAX_ROUNDS ? $definitions : []);
            $inputTokens += (int) $response->usage->inputTokens;
            $outputTokens += (int) $response->usage->outputTokens;

            if ($response->stopReason === 'refusal') {
                throw new AgentProblem('the AI declined this request');
            }

            if ($response->stopReason !== 'tool_use') {
                $text = '';
                foreach ($response->content as $block) {
                    if ($block->type === 'text') {
                        $text .= $block->text;
                    }
                }
                if (trim($text) === '') {
                    throw new AgentProblem('the AI returned an empty answer');
                }

                return ['text' => trim($text), 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens];
            }

            $results = [];
            foreach ($response->content as $block) {
                if ($block->type !== 'tool_use') {
                    continue;
                }
                $input = (array) $block->input;
                $tool = $byName[$block->name] ?? null;

                if ($tool === null) {
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => 'No such tool.', 'isError' => true];

                    continue;
                }

                $onStep(($tool->step)($input));

                try {
                    $content = ($tool->run)($input);
                    $isError = false;
                } catch (ErpProblem $problem) {
                    $content = 'Could not read it: '.$problem->getMessage().'.';
                    $isError = true;
                } catch (Throwable $exception) {
                    Log::error('Agent tool failed', ['tool' => $tool->name, 'error' => $exception->getMessage()]);
                    $content = 'The tool failed on our side.';
                    $isError = true;
                }

                if (mb_strlen($content) > self::MAX_RESULT) {
                    $content = mb_substr($content, 0, self::MAX_RESULT).' [cut short: ask for fewer days]';
                }

                $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => $content, 'isError' => $isError];
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $results];
            $onStep('Thinking about what I found');
        }

        throw new AgentProblem('the agent kept looking without finishing an answer');
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     */
    private function send(string $instructions, array $messages, array $tools): mixed
    {
        try {
            return (new Client(apiKey: $this->apiKey))->beta->messages->create(
                model: config('office.model'),
                maxTokens: self::MAX_TOKENS,
                system: $instructions,
                messages: $messages,
                tools: $tools,
                outputConfig: ['effort' => 'medium'],
                // If a safety filter declines, the API retries on another model by itself.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException) {
            throw new AgentProblem('the AI key was rejected. The owner can enter a valid one in Settings');
        } catch (RateLimitException) {
            throw new AgentProblem('the AI service is busy for this account. Try again in a few minutes');
        } catch (APIStatusException $exception) {
            Log::error('Anthropic API error', ['status' => $exception->status ?? null, 'message' => $exception->getMessage()]);

            throw new AgentProblem('the AI service returned an error');
        } catch (APIConnectionException) {
            throw new AgentProblem('the AI service could not be reached');
        }
    }
}
