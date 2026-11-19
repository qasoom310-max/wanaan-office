<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Message;
use App\Models\PlanPage;
use App\Models\Setting;
use App\Models\User;
use App\Office\AgentModel;
use App\Office\Erp\ErpClient;
use App\Office\Team;
use App\Office\Tools\AgentTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Fakes\FakeAgentModel;
use Tests\TestCase;

class WebsitePlanTest extends TestCase
{
    use RefreshDatabase;

    /** @return Collection<string, AgentTool> */
    private function itTools(): Collection
    {
        return collect(app(Team::class)->toolsFor('it'))->keyBy('name');
    }

    public function test_only_the_it_manager_writes_the_website_plan(): void
    {
        $this->assertTrue($this->itTools()->has('office_plan_save'));
        $this->assertFalse(collect(app(Team::class)->toolsFor('limo'))->contains('name', 'office_plan_save'));
    }

    public function test_the_it_manager_plans_from_real_information_the_erp_and_the_web_with_sources(): void
    {
        Setting::write(ErpClient::URL, 'https://erp.test');
        Setting::write(ErpClient::KEY, 'office_x');
        Setting::write(ErpClient::WORKSPACES, '1');
        $team = app(Team::class);

        $names = array_map(fn ($tool) => $tool->name, $team->toolsFor('it'));
        $this->assertContains('erp_limousine_trips', $names);
        $this->assertNotContains('erp_journal_entries', $names);
        $this->assertFalse($team->searchesWeb('it'), 'Research is the Gulf Planner\'s and Researcher\'s job.');
        $this->assertStringContainsString('"To confirm"', $team->get('it')['brief']);
    }

    public function test_the_it_manager_asks_the_gulf_planner_who_researches_and_answers_in_the_chat(): void
    {
        $owner = User::factory()->owner()->create();
        $model = new FakeAgentModel;
        $model->answerWith = fn (string $instructions): string => str_contains($instructions, 'You are Gulf Planner')
            ? 'Saudi Arabia: VAT 15% (zatca.gov.sa).'
            : 'Planned.';
        $model->useTools = [['office_ask_colleague', ['colleague' => 'pln', 'question' => 'What is VAT on limousine rides in Saudi Arabia?']]];
        $this->app->instance(AgentModel::class, $model);

        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'direct:it', 'body' => 'Plan the booking page.'])->assertOk();

        $this->assertSame('Saudi Arabia: VAT 15% (zatca.gov.sa).', $model->toolResults['office_ask_colleague']);
        $this->assertTrue($model->lastLimits['web_search'] ?? false, 'The planner searched the web.');
        $answer = Message::where('agent', 'pln')->sole();
        $this->assertSame('direct:it', $answer->channel);
        $this->assertSame($owner->id, $answer->owner_id);
        $this->assertStringContainsString('Asked by IT Manager', $answer->body);
        $this->assertSame(1, AgentRun::where('agent', 'pln')->where('status', 'done')->count());
    }

    public function test_the_it_manager_adds_and_changes_pages_and_a_changed_approved_page_needs_approval_again(): void
    {
        $save = $this->itTools()['office_plan_save']->run;

        $this->assertStringContainsString('Added', $save(['title' => 'Book a ride', 'path' => '/book', 'purpose' => 'Customers book a limousine.', 'content' => 'Pick-up, drop-off, date, car type, pay.']));
        $this->assertStringContainsString('already in the plan', $save(['title' => 'Book a ride now', 'purpose' => 'x', 'content' => 'x']));

        $page = PlanPage::sole();
        $page->update(['status' => 'approved', 'approved_at' => now()]);
        $this->assertStringContainsString('waits for the owner', $save(['id' => $page->id, 'title' => 'Book a ride', 'purpose' => 'Customers book and pay.', 'content' => 'Now with child seats.']));
        $this->assertSame('planned', $page->fresh()->status);

        $page->update(['status' => 'building']);
        $this->assertStringContainsString('Not changed', $save(['id' => $page->id, 'title' => 'Book a ride', 'purpose' => 'x', 'content' => 'x']));
    }

    public function test_the_owner_approves_or_asks_for_changes_and_only_the_owner(): void
    {
        $owner = User::factory()->owner()->create();
        $page = PlanPage::create(['title' => 'Home', 'purpose' => 'First impression.', 'content' => 'Hero, countries, book button.', 'status' => 'planned']);

        $this->actingAs(User::factory()->create())->post(route('plan.decide', $page), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($owner)->post(route('plan.decide', $page), ['decision' => 'changes'])->assertSessionHasErrors('note');
        $this->actingAs($owner)->post(route('plan.decide', $page), ['decision' => 'changes', 'note' => 'Add Saudi Arabia first.']);
        $this->assertSame('changes', $page->fresh()->status);

        $this->actingAs($owner)->post(route('plan.decide', $page), ['decision' => 'approve']);
        $this->assertSame('approved', $page->fresh()->status);
        $this->assertNull($page->fresh()->owner_note);

        $this->actingAs($owner)->post(route('plan.decide', $page), ['decision' => 'live'])->assertStatus(422);
        $this->actingAs($owner)->get(route('plan'))->assertOk()->assertSee('Home');
    }
}
