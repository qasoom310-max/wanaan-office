<?php

namespace Tests\Feature;

use App\Jobs\RunAgent;
use App\Models\AgentName;
use App\Models\AgentRun;
use App\Models\Fact;
use App\Models\Message;
use App\Models\User;
use App\Office\AgentModel;
use App\Office\Instructions;
use App\Office\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Fakes\FakeAgentModel;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgentModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new FakeAgentModel;
        $this->app->instance(AgentModel::class, $this->model);
    }

    private function send(User $user, string $channel, string $body): void
    {
        $this->actingAs($user)->postJson(route('office.chat'), ['channel' => $channel, 'body' => $body])->assertOk();
    }

    public function test_the_secretary_answers_in_her_private_chat_and_both_messages_are_kept(): void
    {
        $owner = User::factory()->owner()->create();

        $this->send($owner, 'secretary', 'Remind me to call the bank.');

        $messages = Message::where('channel', 'secretary')->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertSame('Remind me to call the bank.', $messages[0]->body);
        $this->assertSame($owner->id, $messages[0]->owner_id);
        $this->assertSame('sec', $messages[1]->agent);
        $this->assertSame('Noted.', $messages[1]->body);
        $this->assertSame(AgentRun::DONE, AgentRun::sole()->status);
    }

    public function test_nobody_else_can_read_a_private_chat(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->create();
        $this->send($owner, 'secretary', 'Private: check Ali\'s salary.');

        $this->actingAs($staff)->getJson(route('office.state', ['after' => ['secretary' => 0]]))
            ->assertOk()
            ->assertJsonCount(0, 'messages.secretary');
        $this->actingAs($staff)->get(route('office'))->assertOk()->assertDontSee('check Ali');

        $this->actingAs($owner)->getJson(route('office.state', ['after' => ['secretary' => 0]]))
            ->assertJsonCount(2, 'messages.secretary');
    }

    public function test_in_everyone_an_at_mention_picks_the_agent_and_otherwise_the_secretary_answers(): void
    {
        $owner = User::factory()->owner()->create();
        AgentName::create(['agent' => 'acc', 'name' => 'Salman', 'named_by' => $owner->id]);

        $this->send($owner, 'everyone', '@Salman how many drafts are open?');
        $this->send($owner, 'everyone', 'Good morning team');
        $this->send($owner, 'everyone', '@Limousine Manager how was the week?');

        $this->assertSame(['acc', 'sec', 'limo'], Message::whereNotNull('agent')->orderBy('id')->pluck('agent')->all());
        $this->assertNull(Message::where('channel', 'everyone')->first()->owner_id);
    }

    public function test_every_agent_can_be_spoken_to_directly(): void
    {
        $owner = User::factory()->owner()->create();

        $this->send($owner, 'direct:rent', 'What do you need from me?');

        $this->assertSame('rent', Message::whereNotNull('agent')->sole()->agent);
        $this->actingAs($owner)->get(route('office', ['with' => 'rent']))->assertOk()->assertSee('What do you need from me?');
        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'direct:nobody', 'body' => 'Hi'])->assertNotFound();
    }

    public function test_agents_are_told_the_facts_and_whether_they_can_see_real_data(): void
    {
        $owner = User::factory()->owner()->create();
        Fact::create(['text' => 'Deposits are refunded within 7 days.', 'confirmed_by' => $owner->id]);
        Fact::create(['text' => 'Only for the planner.', 'agents' => ['pln'], 'confirmed_by' => $owner->id]);

        $this->send($owner, 'direct:rent', 'When do deposits go back?');

        $this->assertStringContainsString('Deposits are refunded within 7 days.', $this->model->lastInstructions);
        $this->assertStringNotContainsString('Only for the planner.', $this->model->lastInstructions);
        $this->assertStringContainsString('not connected to any company data yet', $this->model->lastInstructions);
        $this->assertSame('user', $this->model->lastMessages[0]['role']);
        $this->assertStringContainsString('When do deposits go back?', end($this->model->lastMessages)['content']);
    }

    public function test_without_the_ai_key_the_agent_says_so_and_the_message_is_kept(): void
    {
        $this->model->configured = false;
        $owner = User::factory()->owner()->create();

        $this->send($owner, 'secretary', 'Hello?');

        $this->assertSame(0, $this->model->calls);
        $this->assertStringContainsString("the AI key isn't connected", Message::where('agent', 'sec')->sole()->body);
        $this->assertTrue(Message::where('body', 'Hello?')->exists());
    }

    public function test_when_the_ai_fails_the_agent_says_why_and_the_run_is_marked_failed(): void
    {
        $this->model->problem = 'the AI service could not be reached';
        $owner = User::factory()->owner()->create();

        $this->send($owner, 'secretary', 'Hello?');

        $this->assertSame(AgentRun::FAILED, AgentRun::sole()->status);
        $this->assertSame("I couldn't answer: the AI service could not be reached. Your message is saved.", Message::where('agent', 'sec')->sole()->body);
    }

    public function test_messages_are_never_changed_or_deleted(): void
    {
        $owner = User::factory()->owner()->create();
        $this->send($owner, 'secretary', 'Keep this forever.');
        $message = Message::where('body', 'Keep this forever.')->sole();

        try {
            $message->delete();
            $this->fail('A message was deleted.');
        } catch (LogicException) {
        }

        try {
            $message->update(['body' => 'Changed']);
            $this->fail('A message was changed.');
        } catch (LogicException) {
        }

        $this->assertSame('Keep this forever.', $message->fresh()->body);
    }

    public function test_the_page_shows_each_working_step_and_a_run_that_stopped_moving_as_stuck(): void
    {
        $owner = User::factory()->owner()->create();
        AgentRun::create(['agent' => 'acc', 'channel' => 'everyone', 'status' => AgentRun::WORKING, 'step' => 'Writing the answer', 'step_number' => 3, 'step_total' => 3, 'heartbeat_at' => now()]);
        AgentRun::create(['agent' => 'sec', 'channel' => 'everyone', 'status' => AgentRun::WORKING, 'step' => 'Reading your message', 'heartbeat_at' => now()->subMinutes(10)]);
        AgentRun::create(['agent' => 'sec', 'channel' => 'secretary', 'owner_id' => User::factory()->create()->id, 'status' => AgentRun::WORKING, 'step' => 'Someone else\'s private work']);

        $this->actingAs($owner)->getJson(route('office.state'))
            ->assertOk()
            ->assertJsonCount(2, 'runs')
            ->assertJsonPath('runs.0.step', 'Writing the answer')
            ->assertJsonPath('runs.0.stuck', false)
            ->assertJsonPath('runs.1.stuck', true);
    }

    public function test_a_run_is_answered_once_even_if_its_job_runs_twice(): void
    {
        $owner = User::factory()->owner()->create();
        $this->send($owner, 'secretary', 'Once only.');

        (new RunAgent(AgentRun::sole()))->handle($this->model, app(Instructions::class), app(Team::class));

        $this->assertSame(1, Message::where('agent', 'sec')->count());
    }

    public function test_an_agent_that_stopped_moving_is_closed_with_a_message(): void
    {
        $owner = User::factory()->owner()->create();
        $run = AgentRun::create(['agent' => 'sec', 'channel' => 'secretary', 'owner_id' => $owner->id, 'status' => AgentRun::WORKING, 'claimed_at' => now(), 'heartbeat_at' => now()->subMinutes(11)]);
        AgentRun::create(['agent' => 'acc', 'channel' => 'everyone', 'status' => AgentRun::WORKING, 'heartbeat_at' => now()]);

        $this->assertSame(1, RunAgent::closeAbandoned());

        $this->assertSame(AgentRun::FAILED, $run->fresh()->status);
        $this->assertStringContainsString('I stopped before finishing', Message::where('agent', 'sec')->sole()->body);
        $this->assertSame($owner->id, Message::where('agent', 'sec')->sole()->owner_id);
    }

    public function test_signed_out_visitors_see_nothing(): void
    {
        $this->get(route('office'))->assertRedirect(route('login'));
        $this->getJson(route('office.state'))->assertUnauthorized();
        $this->postJson(route('office.chat'), ['channel' => 'everyone', 'body' => 'x'])->assertUnauthorized();
    }
}
