<?php

namespace Tests\Feature;

use App\Models\Fact;
use App\Models\Idea;
use App\Models\Message;
use App\Models\MonthlyReport;
use App\Models\User;
use App\Office\AgentModel;
use App\Office\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgentModel;
use Tests\TestCase;

class BoardAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgentModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new FakeAgentModel;
        $this->app->instance(AgentModel::class, $this->model);
    }

    public function test_approving_has_the_it_manager_write_the_requirement_then_it_moves_through_the_board(): void
    {
        $owner = User::factory()->owner()->create();
        $idea = Idea::create(['agent' => 'limo', 'title' => 'Record the destination country on every trip', 'why' => 'Gulf demand.', 'size' => 'Small']);
        $this->model->answer = "## What to build\nA country field on every trip.";

        $this->actingAs($owner)->post(route('ideas.decide', $idea), ['status' => 'yes']);

        $idea->refresh();
        $this->assertSame('ready', $idea->status);
        $this->assertStringContainsString('A country field', $idea->requirement);
        $this->assertStringContainsString('Write its requirement', $this->model->lastMessages[0]['content']);
        $this->assertStringContainsString('Requirement written by the IT Manager', $idea->forClaudeCode());

        $this->actingAs($owner)->get(route('ideas'))->assertOk()->assertSee('Ready to build')->assertSee('Copy for Claude Code');

        $this->actingAs($owner)->post(route('ideas.decide', $idea), ['status' => 'building']);
        $this->assertSame('building', $idea->fresh()->status);
        $this->actingAs($owner)->post(route('ideas.decide', $idea), ['status' => 'yes'])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('ideas.decide', $idea), ['status' => 'done']);
        $this->assertSame('done', $idea->fresh()->status);
        $this->assertTrue(Fact::where('text', 'like', 'Done on the IT board: Record the destination%')->exists());
    }

    public function test_without_the_ai_key_the_request_waits_with_a_note(): void
    {
        $this->model->configured = false;
        $owner = User::factory()->owner()->create();
        $idea = Idea::create(['agent' => 'limo', 'title' => 'X', 'why' => 'Y', 'size' => 'Small']);

        $this->actingAs($owner)->post(route('ideas.decide', $idea), ['status' => 'yes']);

        $this->assertSame('yes', $idea->fresh()->status);
        $this->assertStringContainsString('waits for the AI key', $idea->fresh()->note);
    }

    public function test_only_managers_write_monthly_reports_one_per_month(): void
    {
        $this->assertTrue(collect(app(Team::class)->toolsFor('limo'))->contains('name', 'office_monthly_report'));
        $this->assertFalse(collect(app(Team::class)->toolsFor('txt'))->contains('name', 'office_monthly_report'));

        $tool = collect(app(Team::class)->toolsFor('limo'))->firstWhere('name', 'office_monthly_report');
        $body = "## Summary\n".str_repeat('Trips grew and invoices were paid faster. ', 10);

        $this->assertStringContainsString('Not saved', ($tool->run)(['month' => '2020-01', 'title' => 'Old', 'report' => $body]));
        $this->assertStringContainsString('Not saved', ($tool->run)(['month' => now()->format('Y-m'), 'title' => 'Short', 'report' => 'Too short.']));
        $this->assertStringContainsString('Saved', ($tool->run)(['month' => now()->format('Y-m'), 'title' => 'Limousine, first', 'report' => $body]));
        $this->assertStringContainsString('Saved', ($tool->run)(['month' => now()->format('Y-m'), 'title' => 'Limousine, revised', 'report' => $body]));

        $this->assertSame(1, MonthlyReport::count());
        $this->assertSame('Limousine, revised', MonthlyReport::sole()->title);
    }

    public function test_the_monthly_run_asks_every_manager_in_its_private_chat_and_the_reports_can_be_read_and_downloaded(): void
    {
        $owner = User::factory()->owner()->create();
        $this->model->useTools = [['office_monthly_report', ['month' => now()->subMonthNoOverflow()->format('Y-m'), 'title' => 'Improvement report', 'report' => "## Summary\n".str_repeat('Numbers and findings. ', 20)]]];

        $this->artisan('office:monthly-reports')->assertSuccessful();

        $managers = collect(config('office.agents'))->filter(fn ($agent) => $agent['manager'] ?? false)->count();
        $this->assertSame($managers, MonthlyReport::count());
        $this->assertTrue(Message::where('channel', 'direct:limo')->whereNull('user_id')->whereNull('agent')->exists());

        $report = MonthlyReport::where('agent', 'limo')->sole();
        $this->actingAs($owner)->get(route('reports'))->assertOk()->assertSee('Improvement report');
        $this->actingAs($owner)->get(route('reports.show', $report))->assertOk()->assertSee('Numbers and findings', false);
        $this->actingAs($owner)->get(route('reports.download', $report))->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');

        // Running again the same month asks nobody twice.
        $calls = $this->model->calls;
        $this->artisan('office:monthly-reports')->assertSuccessful();
        $this->assertSame($calls, $this->model->calls);
    }
}
