<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Fact;
use App\Models\Idea;
use App\Models\Question;
use App\Models\Setting;
use App\Models\User;
use App\Office\AgentModel;
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

        foreach (['mkt', 'res', 'txt', 'pic', 'vid'] as $agent) {
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
        $this->assertStringContainsString('Already on the IT board', ($request->run)(['title' => 'driver must enter the end km', 'why' => 'x', 'size' => 'Small']));

        $idea = Idea::sole();
        $this->assertSame('limo', $idea->agent);
        $this->assertSame('wait', $idea->status);
    }
}
