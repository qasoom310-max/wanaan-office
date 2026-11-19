<?php

namespace Tests\Feature;

use App\Models\AgentName;
use App\Models\Fact;
use App\Models\Idea;
use App\Models\Question;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_name_is_set_once_and_must_be_a_unique_person_name(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('names.store'), ['agent' => 'sec', 'name' => '  Noura  '])->assertSessionHasNoErrors();
        $this->assertSame('Noura', AgentName::where('agent', 'sec')->sole()->name);

        $this->actingAs($owner)->post(route('names.store'), ['agent' => 'sec', 'name' => 'Huda'])->assertSessionHasErrors('name');
        $this->actingAs($owner)->post(route('names.store'), ['agent' => 'acc', 'name' => 'noura'])->assertSessionHasErrors('name');
        $this->actingAs($owner)->post(route('names.store'), ['agent' => 'acc', 'name' => 'Secretary'])->assertSessionHasErrors('name');
        $this->actingAs($owner)->post(route('names.store'), ['agent' => 'acc', 'name' => 'R2D2'])->assertSessionHasErrors('name');
        $this->actingAs($owner)->post(route('names.store'), ['agent' => 'acc', 'name' => 'سلمان'])->assertSessionHasNoErrors();

        $this->assertSame('Noura', AgentName::where('agent', 'sec')->sole()->name);
    }

    public function test_only_the_owner_names_agents_confirms_facts_answers_questions_and_approves_it_work(): void
    {
        $staff = User::factory()->create();
        $question = Question::create(['agent' => 'acc', 'question' => 'Late after how many days?', 'why' => 'Fairness.']);
        $idea = Idea::create(['agent' => 'acc', 'title' => 'Shared inbox', 'why' => 'Delays.', 'size' => 'Large']);

        $this->actingAs($staff)->post(route('names.store'), ['agent' => 'sec', 'name' => 'Noura'])->assertForbidden();
        $this->actingAs($staff)->post(route('facts.store'), ['text' => 'I am the boss.'])->assertForbidden();
        $this->actingAs($staff)->post(route('questions.answer', $question), ['answer' => '1'])->assertForbidden();
        $this->actingAs($staff)->post(route('ideas.decide', $idea), ['status' => 'yes'])->assertForbidden();
        $this->actingAs($staff)->get(route('settings'))->assertForbidden();

        $this->assertSame(0, Fact::count());
        $this->assertSame('wait', $idea->fresh()->status);
    }

    public function test_an_answer_becomes_a_fact_for_the_agent_that_asked(): void
    {
        $owner = User::factory()->owner()->create();
        $question = Question::create(['agent' => 'acc', 'question' => 'How many days can an entry stay in draft?', 'why' => 'Fairness.']);

        $this->actingAs($owner)->post(route('questions.answer', $question), ['answer' => '3 days'])->assertSessionHasNoErrors();

        $fact = Fact::sole();
        $this->assertSame('How many days can an entry stay in draft: 3 days', $fact->text);
        $this->assertSame(['acc'], $fact->agents);
        $this->assertFalse($question->fresh()->isOpen());

        $this->actingAs($owner)->post(route('questions.answer', $question), ['answer' => '5 days'])->assertSessionHasErrors('answer');
        $this->assertSame(1, Fact::count());
    }

    public function test_the_owner_approves_or_puts_off_it_work(): void
    {
        $owner = User::factory()->owner()->create();
        $idea = Idea::create(['agent' => 'acc', 'title' => 'Shared inbox', 'why' => 'Delays.', 'size' => 'Large']);

        $this->actingAs($owner)->post(route('ideas.decide', $idea), ['status' => 'later']);
        $this->assertSame('later', $idea->fresh()->status);
        $this->actingAs($owner)->post(route('ideas.decide', $idea), ['status' => 'done'])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('ideas.decide', $idea), ['status' => 'yes']);
        $this->assertSame('yes', $idea->fresh()->status);
    }

    public function test_the_ai_key_is_stored_encrypted_and_never_shown_back(): void
    {
        $owner = User::factory()->owner()->create();
        $key = 'sk-ant-api03-'.str_repeat('x', 40).'WXYZ';

        $this->actingAs($owner)->put(route('settings.update'), ['anthropic_api_key' => 'not-a-key'])->assertSessionHasErrors();
        $this->actingAs($owner)->put(route('settings.update'), ['anthropic_api_key' => $key])->assertSessionHasNoErrors();

        $this->assertSame($key, Setting::read(Setting::ANTHROPIC_API_KEY));
        $this->assertStringNotContainsString('sk-ant', (string) DB::table('settings')->value('value'));
        $this->actingAs($owner)->get(route('settings'))->assertOk()->assertSee('…WXYZ')->assertDontSee($key);
    }

    public function test_the_owner_command_makes_an_account_that_must_change_its_password_and_the_starting_work(): void
    {
        $this->artisan('office:owner', ['name' => 'Owner', 'email' => 'owner@example.test'])
            ->expectsOutputToContain('One-time password')
            ->assertSuccessful();

        $owner = User::sole();
        $this->assertTrue($owner->isOwner());
        $this->assertTrue($owner->must_change_password);
        $this->assertGreaterThan(0, Question::count());
        $this->assertGreaterThan(0, Idea::count());
        $this->assertGreaterThan(0, Fact::count());

        $this->actingAs($owner)->get(route('office'))->assertRedirect(route('password.edit'));

        $this->artisan('office:owner', ['name' => 'Again', 'email' => 'owner@example.test'])->assertFailed();
    }

    public function test_every_page_opens(): void
    {
        $owner = User::factory()->owner()->create();
        $this->artisan('office:owner', ['name' => 'Second', 'email' => 'second@example.test']);

        foreach (['office', 'facts', 'questions', 'ideas', 'settings'] as $page) {
            $this->actingAs($owner)->get(route($page))->assertOk();
        }
    }
}
