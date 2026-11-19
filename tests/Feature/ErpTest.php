<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Office\AgentModel;
use App\Office\Erp\ErpClient;
use App\Office\Erp\ErpProblem;
use App\Office\Team;
use App\Office\Tools\ErpTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeAgentModel;
use Tests\TestCase;

class ErpTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'office_test_key_0123456789abcdef';

    private FakeAgentModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new FakeAgentModel;
        $this->app->instance(AgentModel::class, $this->model);
    }

    private function connect(): void
    {
        Setting::write(ErpClient::URL, 'https://erp.test');
        Setting::write(ErpClient::KEY, self::KEY);
        Setting::write(ErpClient::WORKSPACES, '1');
    }

    private function fakeErp(): void
    {
        Http::fake([
            'erp.test/api/office/v1/workspace' => Http::response(['id' => 1, 'name' => 'Main', 'timezone' => 'Asia/Bahrain', 'now' => '2026-11-19 15:00:00', 'accounting' => true]),
            'erp.test/api/office/v1/accounting/journal-entries*' => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return (int) ($query['after_id'] ?? 0) === 0
                    ? Http::response(['data' => [['id' => 7, 'number' => 'DEMO/0001', 'state' => 'draft']], 'next_after_id' => 7])
                    : Http::response(['data' => [['id' => 9, 'number' => 'DEMO/0002', 'state' => 'draft']], 'next_after_id' => null]);
            },
        ]);
    }

    public function test_without_a_connection_the_main_accountant_is_talk_only(): void
    {
        $team = app(Team::class);

        $this->assertFalse($team->hasData('acc'));
        $this->assertSame(['office_ask_owner'], array_map(fn ($tool) => $tool->name, $team->toolsFor('acc')));
    }

    public function test_once_connected_each_agent_reads_only_its_own_section(): void
    {
        $this->connect();
        $team = app(Team::class);
        $erp = fn (string $agent): array => array_values(array_filter(array_map(fn ($tool) => $tool->name, $team->toolsFor($agent)), fn (string $name): bool => str_starts_with($name, 'erp_')));

        foreach (['acc', 'fin', 'limo', 'rent'] as $agent) {
            $this->assertContains('erp', $team->connectedSources($agent));
        }
        $this->assertSame([], $erp('sec'));
        $this->assertSame([], $erp('mkt'));

        $this->assertSame(['erp_companies', 'erp_staff', 'erp_activity', 'erp_journal_entries'], $erp('acc'));
        $this->assertSame(['erp_companies', 'erp_staff', 'erp_activity', 'erp_journal_entries', 'erp_money_in', 'erp_money_out'], $erp('fin'));
        $this->assertSame(['erp_companies', 'erp_staff', 'erp_activity', 'erp_limousine_trips', 'erp_limousine_invoices', 'erp_limousine_expenses'], $erp('limo'));
        $this->assertSame(['erp_companies', 'erp_staff', 'erp_activity', 'erp_rental_orders', 'erp_rental_fleet'], $erp('rent'));
    }

    public function test_the_accountant_reads_every_page_of_entries_and_each_lookup_is_a_step_on_its_desk(): void
    {
        $this->connect();
        $this->fakeErp();
        $owner = User::factory()->owner()->create();
        $this->model->useTools = [['erp_companies', []], ['erp_journal_entries', ['company_id' => 1, 'state' => 'draft']]];

        $this->actingAs($owner)->postJson(route('office.chat'), ['channel' => 'direct:acc', 'body' => 'Which entries are still in draft?'])->assertOk();

        $this->assertSame(['Opening the ERP: companies', 'Opening the ERP: journal entries of Main'], $this->model->steps);
        $entries = json_decode($this->model->toolResults['erp_journal_entries'], true);
        $this->assertSame(['DEMO/0001', 'DEMO/0002'], array_column($entries['rows'], 'number'));
        $this->assertTrue($entries['complete']);
        $this->assertStringContainsString('erp_ tools (read-only; your sections: accounting)', $this->model->lastInstructions);
        $this->assertSame(AgentRun::DONE, AgentRun::sole()->status);
        $this->assertSame('acc', Message::whereNotNull('agent')->sole()->agent);

        // Only ever reads, always with the key and the company it asked for.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.self::KEY) && $request->hasHeader('X-Workspace', '1'));
    }

    public function test_a_company_the_office_may_not_read_is_never_asked_for(): void
    {
        $this->connect();
        Http::fake();

        $this->expectException(ErpProblem::class);
        try {
            app(ErpClient::class)->get('workspace', 2);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_an_erp_refusal_is_explained_in_plain_words(): void
    {
        $this->connect();
        Http::fake(['erp.test/*' => Http::response(['error' => 'Missing, unknown or revoked office key.'], 401)]);

        $this->expectExceptionMessage('the ERP refused the office key');
        app(ErpTools::class)->forSections(['accounting'])[1]->run->__invoke(['company_id' => 1]);
    }

    public function test_the_owner_connects_the_erp_from_settings_and_the_erp_says_which_companies(): void
    {
        $owner = User::factory()->owner()->create();
        Http::fake([
            'erp.example.test/api/office/v1/workspaces' => Http::response(['data' => [['id' => 1, 'name' => 'Main', 'main' => true], ['id' => 4, 'name' => 'Kaleem', 'main' => false]]]),
            'wrong.example.test/*' => Http::response(['error' => 'Missing, unknown or revoked office key.'], 401),
        ]);

        $this->actingAs($owner)->put(route('settings.erp'), ['erp_url' => 'https://erp.example.test', 'erp_key' => 'not-a-key'])->assertSessionHasErrors('erp_key');
        $this->actingAs($owner)->put(route('settings.erp'), ['erp_url' => 'https://wrong.example.test', 'erp_key' => self::KEY])->assertSessionHasErrors('erp_key');
        $this->assertFalse(app(ErpClient::class)->isConnected());

        $this->actingAs($owner)->put(route('settings.erp'), ['erp_url' => 'https://erp.example.test/', 'erp_key' => self::KEY])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Connected to the ERP: Main, Kaleem.');

        $this->assertSame([1, 4], app(ErpClient::class)->workspaceIds());
        $this->assertSame('https://erp.example.test', Setting::read(ErpClient::URL));
        $this->actingAs(User::factory()->create())->put(route('settings.erp'), ['erp_url' => 'https://erp.example.test', 'erp_key' => self::KEY])->assertForbidden();
    }

    public function test_the_connect_command_saves_the_connection_and_checks_each_company(): void
    {
        $this->fakeErp();
        putenv('OFFICE_ERP_KEY='.self::KEY);

        try {
            $this->artisan('office:connect-erp', ['url' => 'https://erp.test/', '--workspace' => ['1']])
                ->expectsOutputToContain('Company 1: Main answers (accounting on)')
                ->assertSuccessful();
        } finally {
            putenv('OFFICE_ERP_KEY');
        }

        $this->assertSame('https://erp.test', Setting::read(ErpClient::URL));
        $this->assertSame(self::KEY, Setting::read(ErpClient::KEY));
        $this->assertTrue(app(ErpClient::class)->isConnected());
    }
}
