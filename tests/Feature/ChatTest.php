<?php

namespace Tests\Feature;

use App\Jobs\RunAgent;
use App\Models\AgentName;
use App\Models\AgentRun;
use App\Models\Fact;
use App\Models\Message;
use App\Models\MessageRead;
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

    public function test_in_a_team_meeting_every_agent_reads_and_those_with_something_to_say_answer_in_turn(): void
    {
        $owner = User::factory()->owner()->create();
        $this->model->answerWith = fn (string $instructions): string => match (true) {
            str_contains($instructions, 'You are the Main Accountant') => 'I disagree: cash payments make the books harder to check.',
            str_contains($instructions, 'You are the Rent A Car Manager') => 'I agree with the accountant, and deposits need receipts too.',
            default => 'READ',
        };

        $this->send($owner, 'everyone', 'From Sunday we accept cash only.');

        $agents = array_keys(config('office.agents'));
        $this->assertSame(count($agents), AgentRun::where('meeting', true)->where('status', AgentRun::DONE)->count());
        $this->assertSame(count($agents), MessageRead::count());
        $this->assertSame(['acc', 'rent'], Message::whereNotNull('agent')->orderBy('id')->pluck('agent')->all());

        // The Rent A Car Manager spoke after the accountant and saw what was said.
        $rentTurn = $this->model->conversations[array_search('rent', $agents, true)];
        $this->assertStringContainsString('[Main Accountant] I disagree', collect($rentTurn)->pluck('content')->implode("\n"));
        $this->assertStringContainsString('argue your case', $this->model->lastInstructions);

        $this->actingAs($owner)->getJson(route('office.state'))
            ->assertJsonCount(count($agents), 'reads.'.Message::where('body', 'From Sunday we accept cash only.')->value('id'));
    }

    public function test_an_at_mention_in_everyone_asks_only_that_agent(): void
    {
        $owner = User::factory()->owner()->create();
        AgentName::create(['agent' => 'acc', 'name' => 'Salman', 'named_by' => $owner->id]);

        $this->send($owner, 'everyone', '@Salman how many drafts are open?');
        $this->send($owner, 'everyone', '@Limousine Manager how was the week?');

        $this->assertSame(['acc', 'limo'], Message::whereNotNull('agent')->orderBy('id')->pluck('agent')->all());
        $this->assertSame(0, AgentRun::where('meeting', true)->count());
        $this->assertStringNotContainsString('team meeting', $this->model->lastInstructions);
    }

    public function test_without_the_ai_key_a_meeting_gets_one_notice_and_everyone_still_marks_it_read(): void
    {
        $this->model->configured = false;
        $owner = User::factory()->owner()->create();

        $this->send($owner, 'everyone', 'Team, read this.');

        $this->assertSame(['sec'], Message::whereNotNull('agent')->pluck('agent')->all());
        $this->assertSame(count(config('office.agents')), MessageRead::count());
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
        AgentRun::create(['agent' => 'pln', 'channel' => 'everyone', 'status' => AgentRun::WAITING, 'step' => 'Waiting for their turn', 'created_at' => now()->subMinutes(5)]);
        AgentRun::create(['agent' => 'sec', 'channel' => 'secretary', 'owner_id' => User::factory()->create()->id, 'status' => AgentRun::WORKING, 'step' => 'Someone else\'s private work']);

        $this->actingAs($owner)->getJson(route('office.state'))
            ->assertOk()
            ->assertJsonCount(3, 'runs')
            ->assertJsonPath('runs.0.step', 'Writing the answer')
            ->assertJsonPath('runs.0.stuck', false)
            ->assertJsonPath('runs.1.stuck', true)
            ->assertJsonPath('runs.2.waiting', true)
            ->assertJsonPath('runs.2.stuck', false);
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

    public function test_turns_that_were_never_started_are_picked_up_later(): void
    {
        $owner = User::factory()->owner()->create();
        $message = Message::create(['channel' => 'everyone', 'user_id' => $owner->id, 'body' => 'Meeting cut off.']);
        $this->travel(-2)->minutes();
        $run = AgentRun::create(['agent' => 'acc', 'channel' => 'everyone', 'message_id' => $message->id, 'meeting' => true, 'status' => AgentRun::WAITING]);
        $this->travelBack();

        $this->assertSame(1, RunAgent::resumeUnclaimed());
        $this->assertSame(AgentRun::DONE, $run->fresh()->status);
        $this->assertSame('acc', Message::whereNotNull('agent')->sole()->agent);
        $this->assertSame(0, RunAgent::resumeUnclaimed());
    }

    public function test_signed_out_visitors_see_nothing(): void
    {
        $this->get(route('office'))->assertRedirect(route('login'));
        $this->getJson(route('office.state'))->assertUnauthorized();
        $this->postJson(route('office.chat'), ['channel' => 'everyone', 'body' => 'x'])->assertUnauthorized();
    }
}
