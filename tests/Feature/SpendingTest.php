<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Setting;
use App\Models\User;
use App\Office\AgentModel;
use App\Office\Spending;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgentModel;
use Tests\TestCase;

class SpendingTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgentModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new FakeAgentModel;
        $this->app->instance(AgentModel::class, $this->model);
    }

    private function spent(string $agent, int $input, int $output): void
    {
        AgentRun::create(['agent' => $agent, 'channel' => 'everyone', 'status' => AgentRun::DONE, 'input_tokens' => $input, 'output_tokens' => $output]);
    }

    public function test_spending_is_worked_out_from_tokens_at_list_prices(): void
    {
        // Opus 5.5: $4 per million in, $20 per million out.
        $this->spent('acc', 1_000_000, 100_000);
        $this->spent('sec', 500_000, 0);

        $spending = app(Spending::class);
        $this->assertEqualsWithDelta(8.0, $spending->thisMonth(), 0.0001);
        $this->assertEqualsWithDelta(8.0, $spending->today(), 0.0001);
        $this->assertSame(['acc', 'sec'], array_keys($spending->byAgentThisMonth()));
    }

    public function test_each_agent_spends_by_the_rules_for_its_rank(): void
    {
        Setting::write(Spending::BUDGET, '10');
        $spending = app(Spending::class);

        $this->spent('sec', 1_000_000, 0); // $4 = 40%
        $this->assertSame(Spending::NORMAL, $spending->modeFor('acc'));

        $this->spent('sec', 1_000_000, 0); // $8 = 80%
        $this->assertSame(Spending::TIGHT, $spending->modeFor('acc'));
        $this->assertSame(Spending::TIGHT, $spending->modeFor('fin'));

        $this->spent('sec', 500_000, 0); // $10 = 100%
        $this->assertSame(Spending::STOPPED, $spending->modeFor('acc'), 'An employee stops at the budget.');
        $this->assertSame(Spending::RESERVE, $spending->modeFor('fin'), 'A manager may use the reserve.');

        $this->spent('sec', 750_000, 0); // $13 = 130%
        $this->assertSame(Spending::STOPPED, $spending->modeFor('fin'), 'Nobody goes past the reserve.');
        $this->assertEqualsWithDelta(13.0, $spending->ceiling(), 0.0001);
    }

    public function test_near_the_budget_agents_are_told_to_speak_less_and_work_lighter(): void
    {
        $owner = User::factory()->owner()->create();
        Setting::write(Spending::BUDGET, '10');
        $this->spent('sec', 2_100_000, 0); // 84%

        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'direct:acc', 'body' => 'Check the drafts.'])->assertOk();

        $this->assertSame(1, $this->model->calls);
        $this->assertStringContainsString('Speak less', $this->model->lastInstructions);
        $this->assertSame(['effort' => 'low', 'max_rounds' => 6], $this->model->lastLimits);
    }

    public function test_over_the_budget_an_employee_stops_and_a_manager_answers_from_the_reserve(): void
    {
        $owner = User::factory()->owner()->create();
        Setting::write(Spending::BUDGET, '5');
        $this->spent('sec', 1_250_000, 0); // $5 = 100%

        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'direct:acc', 'body' => 'Check the drafts.'])->assertOk();
        $this->assertSame(0, $this->model->calls);
        $this->assertStringContainsString('AI budget ($5.00) is used up', Message::where('agent', 'acc')->sole()->body);
        $this->assertStringContainsString('Managers may still answer', Message::where('agent', 'acc')->sole()->body);
        $this->assertTrue(Message::where('body', 'Check the drafts.')->exists());

        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'direct:fin', 'body' => 'Is the supplier payment safe?'])->assertOk();
        $this->assertSame(1, $this->model->calls);
        $this->assertStringContainsString('small reserve', $this->model->lastInstructions);
        $this->assertSame(['effort' => 'low', 'max_rounds' => 3], $this->model->lastLimits);
    }

    public function test_a_meeting_over_budget_gets_one_notice_and_everyone_still_reads(): void
    {
        $owner = User::factory()->owner()->create();
        Setting::write(Spending::BUDGET, '1');
        $this->spent('acc', 1_000_000, 0); // 400%: past the reserve too

        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'everyone', 'body' => 'Team, read this.'])->assertOk();

        $this->assertSame(0, $this->model->calls);
        $this->assertSame(['sec'], Message::whereNotNull('agent')->pluck('agent')->all());
        $this->assertSame(count(config('office.agents')), MessageRead::count());
    }

    public function test_under_budget_agents_work_and_their_cost_is_recorded(): void
    {
        $owner = User::factory()->owner()->create();
        Setting::write(Spending::BUDGET, '50');

        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'secretary', 'body' => 'Hello'])->assertOk();

        $this->assertSame(1, $this->model->calls);
        $this->assertGreaterThan(0, app(Spending::class)->thisMonth());
    }

    public function test_only_the_owner_sets_the_budget_and_the_office_shows_it(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs(User::factory()->create())->put(route('settings.budget'), ['budget' => 1000])->assertForbidden();

        $this->actingAs($owner)->get(route('office'))->assertSee('No monthly budget set');
        $this->actingAs($owner)->put(route('settings.budget'), ['budget' => 0])->assertSessionHasErrors('budget');
        $this->actingAs($owner)->put(route('settings.budget'), ['budget' => 40])->assertSessionHasNoErrors();

        $this->assertSame(40.0, app(Spending::class)->budget());
        $this->actingAs($owner)->get(route('office'))->assertSee('$40.00 left this month');
    }
}
