<?php

namespace App\Office;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Illuminate\Support\Facades\Log;

/**
 * Asks Claude for an agent's answer.
 */
class ClaudeAgentModel implements AgentModel
{
    private const MAX_TOKENS = 16000;

    public function __construct(private ?string $apiKey) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    public function reply(string $instructions, array $messages): array
    {
        try {
            $response = (new Client(apiKey: $this->apiKey))->beta->messages->create(
                model: config('office.model'),
                maxTokens: self::MAX_TOKENS,
                system: $instructions,
                messages: $messages,
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

        if ($response->stopReason === 'refusal') {
            throw new AgentProblem('the AI declined this request');
        }

        $text = '';
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        if (trim($text) === '') {
            throw new AgentProblem('the AI returned an empty answer');
        }

        return [
            'text' => trim($text),
            'input_tokens' => (int) $response->usage->inputTokens,
            'output_tokens' => (int) $response->usage->outputTokens,
        ];
    }
}
