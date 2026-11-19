<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\Idea;
use App\Office\AgentModel;
use App\Office\AgentProblem;
use App\Office\Instructions;
use App\Office\Spending;
use App\Office\Team;
use App\Office\Tools\ColleagueTool;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Once the owner approves a request, the IT Manager writes its requirement: what to
 * build, who uses it, the rules, how to check it, which system it touches. Saved on the
 * request, which then moves to "Ready to build".
 */
class WriteRequirement implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public Idea $idea) {}

    public function handle(AgentModel $model, Instructions $instructions, Team $team, Spending $spending): void
    {
        $idea = $this->idea->refresh();
        if ($idea->status !== 'yes') {
            return;
        }

        $budget = $spending->modeFor('it');
        if (! $model->isConfigured() || $budget === Spending::STOPPED) {
            $idea->update(['note' => ! $model->isConfigured() ? 'The requirement waits for the AI key.' : 'The requirement waits: this month\'s AI budget is used up.']);

            return;
        }

        set_time_limit($this->timeout);
        $run = AgentRun::create(['agent' => 'it', 'channel' => 'board', 'status' => AgentRun::WORKING, 'step' => 'Writing a requirement', 'step_number' => 1, 'step_total' => 3, 'claimed_at' => now(), 'heartbeat_at' => now()]);

        $asker = $team->exists($idea->agent) ? $team->label($idea->agent) : $idea->agent;
        $ask = "[Owner (owner)] I approved this request on the IT board. Write its requirement so Claude Code can build it without guessing.\n\n"
            ."Request: {$idea->title}\nAsked by: {$asker}\nWhy: {$idea->why}\nSize: {$idea->size}\n\n"
            .'Use these headings: What to build · Who uses it and how · Rules and edge cases · Which system it touches (ERP, SEO system, office, website) · How to check it works · Risks and what must not break · To confirm (anything you assumed). '
            .'Look at the real data you can read before writing, and ask the colleague who asked if something is unclear. Reply with the requirement only.';

        try {
            $reply = $model->reply(
                $instructions->office(),
                [['role' => 'user', 'content' => [['type' => 'text', 'text' => $ask]]], ['role' => 'system', 'content' => $instructions->agent('it', 'board', false, $budget)]],
                [...$team->toolsFor('it'), app(ColleagueTool::class)->for('it', 'board', null)],
                function (string $label) use ($run): void {
                    $run->moveTo($label, min($run->step_number + 1, 250), max($run->step_total, $run->step_number + 2));
                },
                $budget === Spending::NORMAL ? [] : ['effort' => 'low', 'max_rounds' => 4],
            );
        } catch (AgentProblem $problem) {
            $run->update(['status' => AgentRun::FAILED, 'error' => $problem->getMessage(), 'finished_at' => now()]);
            $idea->update(['note' => 'The requirement could not be written: '.$problem->getMessage().'. Press "Write it again".']);

            return;
        } catch (Throwable) {
            $run->update(['status' => AgentRun::FAILED, 'error' => 'failed', 'finished_at' => now()]);
            $idea->update(['note' => 'The requirement could not be written. Press "Write it again".']);

            return;
        }

        $run->update([
            'status' => AgentRun::DONE, 'step' => null, 'finished_at' => now(),
            'input_tokens' => $reply['input_tokens'], 'output_tokens' => $reply['output_tokens'],
            'cache_write_tokens' => $reply['cache_write_tokens'] ?? 0, 'cache_read_tokens' => $reply['cache_read_tokens'] ?? 0,
        ]);
        $idea->update(['requirement' => $reply['text'], 'status' => 'ready', 'note' => null]);
    }
}
