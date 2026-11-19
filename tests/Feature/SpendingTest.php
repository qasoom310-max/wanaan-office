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

    public function test_once_the_budget_is_used_up_agents_stop_calling_the_ai_and_say_so(): void
    {
        $owner = User::factory()->owner()->create();
        Setting::write(Spending::BUDGET, '5');
        $this->spent('acc', 1_000_000, 100_000);

        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'direct:acc', 'body' => 'Check the drafts.'])->assertOk();

        $this->assertSame(0, $this->model->calls);
        $this->assertStringContainsString('AI budget ($5.00) is used up', Message::where('agent', 'acc')->sole()->body);
        $this->assertTrue(Message::where('body', 'Check the drafts.')->exists());
    }

    public function test_a_meeting_over_budget_gets_one_notice_and_everyone_still_reads(): void
    {
        $owner = User::factory()->owner()->create();
        Setting::write(Spending::BUDGET, '1');
        $this->spent('acc', 1_000_000, 0);

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
