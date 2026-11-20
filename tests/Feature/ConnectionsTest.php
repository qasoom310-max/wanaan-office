<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Fact;
use App\Models\Idea;
use App\Models\MonthlyReport;
use App\Models\Question;
use App\Models\Setting;
use App\Models\User;
use App\Office\AgentModel;
use App\Office\Erp\ErpClient;
use App\Office\Instructions;
use App\Office\Seo\SeoClient;
use App\Office\Spending;
use App\Office\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeAgentModel;
use Tests\TestCase;

/**
 * Everyone connected as planned: marketing to the SEO system, research and planning to
 * the web, the Secretary and IT to the office, and every agent able to ask the owner
 * (managers and IT also able to ask IT).
 */
class ConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgentModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new FakeAgentModel;
        $this->app->instance(AgentModel::class, $this->model);
    }

    /** @return list<string> */
    private function toolNames(string $agent): array
    {
        return array_map(fn ($tool) => $tool->name, app(Team::class)->toolsFor($agent));
    }

    public function test_the_marketing_team_reads_the_seo_system_once_it_is_connected(): void
    {
        $this->assertNotContains('seo_campaigns', $this->toolNames('txt'));

        Setting::write(SeoClient::URL, 'https://seo.test');
        Setting::write(SeoClient::KEY, 'seo_office_test');
        Http::fake(['seo.test/api/office/v1/projects' => Http::response(['data' => [['id' => 1, 'name' => 'Wanaan Rental Car']]])]);

        foreach (['mkt', 'res', 'txt', 'pic', 'vid', 'pln', 'dev'] as $agent) {
            foreach (['seo_campaigns', 'seo_pages', 'seo_links', 'seo_history', 'seo_recommendations'] as $tool) {
                $this->assertContains($tool, $this->toolNames($agent), "{$agent}: {$tool}");
            }
        }
        $this->assertNotContains('seo_campaigns', $this->toolNames('acc'));

        $owner = User::factory()->owner()->create();
        $this->model->useTools = [['seo_projects', []]];
        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'direct:txt', 'body' => 'Plan this week\'s posts.'])->assertOk();

        $this->assertSame(['Opening the SEO system: projects'], $this->model->steps);
        $this->assertStringContainsString('Wanaan Rental Car', $this->model->toolResults['seo_projects']);
        $this->assertStringContainsString('seo_ tools (read-only)', $this->model->lastInstructions);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer seo_office_test'));
    }

    public function test_only_the_it_manager_writes_and_only_into_the_new_websites_project(): void
    {
        Setting::write(SeoClient::URL, 'https://seo.test');
        Setting::write(SeoClient::KEY, 'seo_office_test');
        $this->assertNotContains('seo_plan_article', $this->toolNames('it'), 'No write tools before a project is given.');

        Setting::write(SeoClient::WRITE_PROJECT, '7');
        Http::fake([
            'seo.test/api/office/v1/projects/7/content' => Http::response(['id' => 3, 'subject' => 'Bahrain to Riyadh'], 201),
            'seo.test/api/office/v1/projects/7/sections' => Http::response(['error' => 'already exists here (id 2).'], 409),
        ]);

        $this->assertContains('seo_plan_article', $this->toolNames('it'));
        $this->assertNotContains('seo_plan_article', $this->toolNames('mkt'));

        $tools = collect(app(Team::class)->toolsFor('it'))->keyBy('name');
        $this->assertStringContainsString('"id":3', ($tools['seo_plan_article']->run)(['kind' => 'post', 'subject' => 'Bahrain to Riyadh', 'language' => 'en', 'topic_id' => 2, 'scheduled_on' => '2027-02-01']));
        $this->assertStringContainsString('already exists', ($tools['seo_add_section']->run)(['name' => 'Airport transfers']));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/projects/7/content') && $request['topic_id'] === 2);
    }

    public function test_the_company_developer_knows_the_whole_company_but_no_private_chat(): void
    {
        Setting::write(ErpClient::URL, 'https://erp.test');
        Setting::write(ErpClient::KEY, 'office_x');
        Setting::write(ErpClient::WORKSPACES, '1');
        Setting::write(SeoClient::URL, 'https://seo.test');
        Setting::write(SeoClient::KEY, 'seo_office_test');

        $names = $this->toolNames('dev');
        foreach (['erp_journal_entries', 'erp_money_in', 'erp_money_out', 'erp_limousine_trips', 'erp_rental_orders', 'erp_rental_fleet', 'seo_campaigns', 'office_monthly_reports', 'office_it_board', 'office_team_activity', 'office_request_it', 'office_monthly_report'] as $tool) {
            $this->assertContains($tool, $names, $tool);
        }
        $this->assertTrue(app(Team::class)->searchesWeb('dev'));

        // The Gulf Planner reads the same company numbers.
        foreach (['erp_journal_entries', 'erp_money_in', 'erp_limousine_trips', 'erp_rental_orders'] as $tool) {
            $this->assertContains($tool, $this->toolNames('pln'), "pln: {$tool}");
        }
        $this->assertTrue(app(Spending::class)->isManager('dev'));

        $owner = User::factory()->owner()->create();
        MonthlyReport::create(['agent' => 'limo', 'month' => now()->format('Y-m'), 'title' => 'Limousine report', 'body' => 'Trips up 12%.']);
        $reports = collect(app(Team::class)->toolsFor('dev'))->firstWhere('name', 'office_monthly_reports');
        $list = json_decode(($reports->run)([]), true);
        $this->assertSame('Limousine report', $list[0]['title']);
        $this->assertStringContainsString('Trips up 12%', ($reports->run)(['report_id' => $list[0]['report_id']]));
    }

    public function test_research_and_planning_search_the_web_and_searches_are_counted_in_spending(): void
    {
        $team = app(Team::class);
        $this->assertTrue($team->searchesWeb('res'));
        $this->assertTrue($team->searchesWeb('pln'));
        $this->assertFalse($team->searchesWeb('acc'));

        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'direct:pln', 'body' => 'Which Gulf country next?'])->assertOk();
        $this->assertTrue($this->model->lastLimits['web_search'] ?? false);
        $this->assertStringContainsString('You can search the web', $this->model->lastInstructions);

        AgentRun::create(['agent' => 'pln', 'channel' => 'secretary', 'status' => AgentRun::DONE, 'web_searches' => 100]);
        $this->assertEqualsWithDelta(1.0, app(Spending::class)->agentThisMonth('pln') - $this->fakeTokenCost(), 0.0001);
    }

    private function fakeTokenCost(): float
    {
        // The fake answer itself used 120 tokens in and 8 out.
        return app(Spending::class)->costOf(120, 8);
    }

    public function test_the_secretary_reads_the_office_but_never_a_private_chat(): void
    {
        $owner = User::factory()->owner()->create();
        Question::create(['agent' => 'acc', 'question' => 'How many draft days?', 'why' => 'Fairness.']);
        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'direct:fin', 'body' => 'Private: salary of Ali.'])->assertOk();
        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'everyone', 'body' => '@Secretary what is open?'])->assertOk();

        $this->assertContains('office_open_questions', $this->toolNames('sec'));
        $this->assertContains('office_it_board', $this->toolNames('it'));

        $tools = collect(app(Team::class)->toolsFor('sec'))->keyBy('name');
        $this->assertStringContainsString('How many draft days?', ($tools['office_open_questions']->run)([]));
        $activity = ($tools['office_team_activity']->run)(['days' => 2]);
        $this->assertStringContainsString('what is open?', $activity);
        $this->assertStringNotContainsString('salary of Ali', $activity);
    }

    public function test_managers_may_ask_as_many_questions_as_they_need_and_every_agent_knows_its_waiting_ones(): void
    {
        $ask = collect(app(Team::class)->toolsFor('fin'))->firstWhere('name', 'office_ask_owner');

        foreach (['Which bank received Trust Services transfers?', 'What is the Bahrain Cab arrangement?', 'Are family food transfers drawings?', 'Who approves petty cash above 50?', 'Is the Al Salam account the main one?'] as $question) {
            $this->assertSame('Added to the owner\'s Questions page.', ($ask->run)(['question' => $question, 'why' => 'Books.']));
        }
        $this->assertSame(5, Question::where('agent', 'fin')->count());

        $own = app(Instructions::class)->agent('fin', 'direct:fin');
        $this->assertStringContainsString('What is the Bahrain Cab arrangement?', $own);
        $this->assertStringContainsString('as many as your work needs', $own);
    }

    public function test_any_agent_can_ask_the_owner_but_only_three_at_a_time(): void
    {
        $ask = collect(app(Team::class)->toolsFor('txt'))->firstWhere('name', 'office_ask_owner');

        foreach (['Which clients?', 'Which tone?', 'Which days?'] as $question) {
            $this->assertSame('Added to the owner\'s Questions page.', ($ask->run)(['question' => $question, 'why' => 'Posts.']));
        }
        $this->assertStringContainsString('already on the Questions page', ($ask->run)(['question' => 'Which tone?', 'why' => 'x']));
        $this->assertStringContainsString('Not added', ($ask->run)(['question' => 'A fourth?', 'why' => 'x']));
        $this->assertSame(3, Question::where('agent', 'txt')->count());
    }

    public function test_an_it_request_the_owner_already_decided_is_never_filed_again(): void
    {
        $owner = User::factory()->owner()->create();
        $approved = Idea::create(['agent' => 'acc', 'title' => 'Record customer WhatsApp chats in the ERP (a shared inbox)', 'why' => 'Reply times cannot be measured.', 'size' => 'Large']);
        $this->actingAs($owner)->post(route('ideas.decide', $approved), ['status' => 'yes']);
        $later = Idea::create(['agent' => 'txt', 'title' => 'Apply for LinkedIn company page posting API', 'why' => 'Post automatically.', 'size' => 'Medium']);
        $this->actingAs($owner)->post(route('ideas.decide', $later), ['status' => 'later']);

        $request = collect(app(Team::class)->toolsFor('fin'))->firstWhere('name', 'office_request_it');

        $this->assertStringContainsString('already approved', ($request->run)(['title' => 'A shared inbox so customer WhatsApp chats are recorded in the ERP', 'why' => 'Measure reply times.', 'size' => 'Large']));
        $this->assertStringContainsString('put this off', ($request->run)(['title' => 'LinkedIn posting API for the company page', 'why' => 'x', 'size' => 'Medium']));
        $this->assertSame(2, Idea::count());

        $this->assertStringContainsString('waits for the owner', ($request->run)(['title' => 'Petty cash advances need a receipt photo', 'why' => 'Shortfalls cannot be checked.', 'size' => 'Small']));
        $this->assertSame(3, Idea::count());
    }

    public function test_an_answered_question_is_never_asked_again_even_in_other_words_or_by_another_agent(): void
    {
        $owner = User::factory()->owner()->create();
        $question = Question::create(['agent' => 'acc', 'question' => 'How many days can an entry stay in draft before it counts as late?', 'why' => 'Fairness.']);
        $this->actingAs($owner)->post(route('questions.answer', $question), ['answer' => '3 days']);

        $ask = collect(app(Team::class)->toolsFor('fin'))->firstWhere('name', 'office_ask_owner');
        $reply = ($ask->run)(['question' => 'After how many days in draft is an entry counted late?', 'why' => 'x']);

        $this->assertStringContainsString('already answered', $reply);
        $this->assertStringContainsString('3 days', $reply);
        $this->assertSame(1, Question::count());

        // A genuinely new question still goes through.
        $this->assertSame('Added to the owner\'s Questions page.', ($ask->run)(['question' => 'Above what amount must a payment wait for approval?', 'why' => 'x']));
    }

    public function test_a_question_a_fact_already_answers_is_not_asked(): void
    {
        $owner = User::factory()->owner()->create();
        Fact::create(['text' => 'Deposits are refunded within 7 days of the car coming back.', 'confirmed_by' => $owner->id]);

        $ask = collect(app(Team::class)->toolsFor('rent'))->firstWhere('name', 'office_ask_owner');

        $this->assertStringContainsString('a confirmed fact already answers', ($ask->run)(['question' => 'Within how many days are deposits refunded after the car comes back?', 'why' => 'x']));
        $this->assertSame(0, Question::count());
    }

    public function test_managers_and_it_can_ask_it_for_improvements_that_wait_for_the_owner_but_employees_cannot(): void
    {
        $this->assertContains('office_request_it', $this->toolNames('limo'));
        $this->assertContains('office_request_it', $this->toolNames('it'));
        $this->assertNotContains('office_request_it', $this->toolNames('acc'));
        $this->assertNotContains('office_request_it', $this->toolNames('txt'));

        $request = collect(app(Team::class)->toolsFor('limo'))->firstWhere('name', 'office_request_it');
        $this->assertStringContainsString('waits for the owner', ($request->run)(['title' => 'Driver must enter the end km', 'why' => 'Trips have no mileage.', 'size' => 'Small']));
        $this->assertStringContainsString('already on the IT board', ($request->run)(['title' => 'driver must enter the end km', 'why' => 'x', 'size' => 'Small']));

        $idea = Idea::sole();
        $this->assertSame('limo', $idea->agent);
        $this->assertSame('wait', $idea->status);
    }
}
