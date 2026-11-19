<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageFile;
use App\Models\User;
use App\Office\AgentModel;
use App\Office\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Fakes\FakeAgentModel;
use Tests\TestCase;

class ChatFilesTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgentModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->model = new FakeAgentModel;
        $this->app->instance(AgentModel::class, $this->model);
    }

    private function xlsx(): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('November')->fromArray([['Car', 'Days'], ['Yaris', 12], ['Camry', 7]]);
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'fleet.xlsx', null, null, true);
    }

    public function test_files_are_saved_privately_and_the_agent_reads_them(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('office.chat'), [
            'channel' => 'direct:rent',
            'body' => 'Check these.',
            'files' => [
                UploadedFile::fake()->image('damage.jpg'),
                UploadedFile::fake()->createWithContent('bill.pdf', "%PDF-1.4\n%fake\n"),
                UploadedFile::fake()->createWithContent('rates.csv', "car,rate\nYaris,12\n"),
                $this->xlsx(),
            ],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(4, MessageFile::count());
        foreach (MessageFile::all() as $file) {
            Storage::disk('local')->assertExists($file->path);
            $this->assertStringStartsWith('chat-files/', $file->path);
        }

        $last = $this->model->rawMessages[count($this->model->rawMessages) - 2]['content'];
        $types = array_column($last, 'type');
        $this->assertContains('image', $types);
        $this->assertContains('document', $types);
        $text = implode("\n", array_column($last, 'text'));
        $this->assertStringContainsString('Yaris,12', $text);
        $this->assertStringContainsString('## Sheet: November', $text);
        $this->assertStringContainsString("Camry\t7", $text);
    }

    public function test_a_file_alone_can_be_sent_and_bad_files_are_refused(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('office.chat'), ['channel' => 'secretary', 'files' => [UploadedFile::fake()->image('receipt.png')]], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('Sent a file.', Message::whereNull('agent')->sole()->body);

        $this->actingAs($owner)->post(route('office.chat'), ['channel' => 'secretary', 'files' => [UploadedFile::fake()->create('virus.exe', 10)]], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($owner)->post(route('office.chat'), ['channel' => 'secretary', 'files' => [UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf')]], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($owner)->post(route('office.chat'), ['channel' => 'secretary'], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_only_someone_who_may_read_the_chat_may_open_its_files_and_files_are_never_deleted(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->create();
        $this->actingAs($owner)->post(route('office.chat'), ['channel' => 'direct:fin', 'body' => 'Private.', 'files' => [UploadedFile::fake()->image('salary.png')]], ['Accept' => 'application/json']);
        $this->actingAs($owner)->post(route('office.chat'), ['channel' => 'everyone', 'body' => '@Secretary shared.', 'files' => [UploadedFile::fake()->image('plan.png')]], ['Accept' => 'application/json']);
        [$private, $shared] = MessageFile::orderBy('id')->get()->all();

        $this->actingAs($owner)->get(route('office.file', $private))->assertOk();
        $this->actingAs($staff)->get(route('office.file', $private))->assertNotFound();
        $this->actingAs($staff)->get(route('office.file', $shared))->assertOk();
        auth()->logout();
        $this->get(route('office.file', $shared))->assertRedirect(route('login'));

        $this->expectException(LogicException::class);
        $private->delete();
    }

    public function test_an_agent_opens_an_earlier_file_only_from_its_own_conversation(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->post(route('office.chat'), ['channel' => 'direct:rent', 'body' => 'Old list.', 'files' => [UploadedFile::fake()->createWithContent('old.csv', "car\nYaris\n")]], ['Accept' => 'application/json']);
        $this->actingAs($owner)->post(route('office.chat'), ['channel' => 'direct:fin', 'body' => 'Other chat.', 'files' => [UploadedFile::fake()->createWithContent('other.csv', "x\n1\n")]], ['Accept' => 'application/json']);
        [$mine, $theirs] = MessageFile::orderBy('id')->get()->all();

        $open = collect(app(Team::class)->toolsFor('rent', 'direct:rent', $owner->id))->firstWhere('name', 'office_open_file');

        $this->assertStringContainsString('Yaris', json_encode(($open->run)(['file_id' => $mine->id])));
        $this->assertSame('No such file in this conversation.', ($open->run)(['file_id' => $theirs->id]));
    }
}
