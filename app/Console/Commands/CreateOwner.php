<?php

namespace App\Console\Commands;

use App\Models\Fact;
use App\Models\Idea;
use App\Models\Question;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('office:owner {name} {email}')]
#[Description('Create the owner\'s account with a one-time password, and the office\'s starting questions, IT board and facts')]
class CreateOwner extends Command
{
    public function handle(): int
    {
        $data = ['name' => $this->argument('name'), 'email' => Str::lower($this->argument('email'))];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = Str::password(16, symbols: false);

        $owner = new User([...$data, 'password' => $password]);
        $owner->is_owner = true;
        $owner->must_change_password = true;
        $owner->save();

        $this->startingWork($owner);

        $this->info("Owner created: {$owner->email}");
        $this->line("One-time password: {$password}");
        $this->warn('You will be asked to choose your own password at first sign-in.');

        return self::SUCCESS;
    }

    /**
     * What the owner already decided in planning, and what the agents already need to know.
     */
    private function startingWork(User $owner): void
    {
        if (Fact::doesntExist()) {
            foreach ([
                'Agents read company data only. They never change, approve, pay or publish anything; the owner decides.',
                'No money leaves the company without the owner pressing Approve. The Accounts Manager may only flag and hold payments.',
                'Chat history is never deleted.',
                'Marketing follows the keywords and calendar chosen in the SEO system, and is judged on enquiries and customers, not likes.',
            ] as $text) {
                Fact::create(['text' => $text, 'confirmed_by' => $owner->id]);
            }
        }

        if (Question::doesntExist()) {
            foreach ([
                ['acc', 'How many days can an entry stay in draft before it counts as late?', 'Without this I cannot flag delays fairly.'],
                ['acc', 'Which staff are accountants and which are customer service?', 'So delays go to the right person in the weekly report.'],
                ['acc', 'How long can a customer wait for a WhatsApp reply before it is late?', 'Customer service delay is half of my weekly report.'],
                ['sec', 'What time do you want the daily brief, and by email or here only?', 'So the brief arrives when you actually read it.'],
                ['fin', 'Above what amount must every outgoing payment wait for you?', 'Needed before I start. Below it, I only report.'],
                ['limo', 'What must my weekly report show? (trips done, cancelled, unpaid invoices, driver hours…)', 'I report what you decide, in the same shape every week.'],
                ['rent', 'Which rules must every rental follow? (deposit, ID checks, late return charge, damage)', 'I cannot check rentals against rules nobody wrote down.'],
                ['txt', 'Which companies or industries are our target corporate clients on LinkedIn?', 'LinkedIn posts only work if they speak to one kind of buyer.'],
                ['pln', 'Which Gulf country comes after Bahrain, if you already know?', 'Planning one country well beats a vague plan for six.'],
            ] as [$agent, $question, $why]) {
                Question::create(['agent' => $agent, 'question' => $question, 'why' => $why]);
            }
        }

        if (Idea::doesntExist()) {
            foreach ([
                ['acc', 'Let the office read the ERP, read-only', 'Every report needs ERP data, and no agent may ever change it.', 'Small', 'done', 'Live: ERP pull request #73'],
                ['mkt', 'Let the marketing team read the SEO system, read-only', 'Marketing should follow what the SEO system already chose.', 'Small', 'done', 'Live: SEO pull request #1'],
                ['acc', 'Record customer WhatsApp chats in the ERP (a shared inbox)', 'Staff reply from their own phones, so reply times cannot be measured at all today.', 'Large', 'wait', null],
                ['fin', 'Payment hold inside the ERP', 'A flagged payment cannot be confirmed until the owner approves. A hold kept only in the office would be easy to go around.', 'Medium', 'wait', null],
                ['mkt', 'Record where each enquiry came from (LinkedIn, Instagram, Google, walk-in…)', 'So marketing is judged on enquiries, not likes. Check first whether the ERP already has this.', 'Small', 'wait', null],
                ['rent', 'Write Rent A Car rules into the ERP (deposit, ID check, late return, damage)', 'Rentals can only be checked against rules the system knows.', 'Medium', 'wait', null],
                ['txt', 'Apply for LinkedIn\'s company-page posting API', 'Only worth it once hand-posted LinkedIn posts bring company enquiries.', 'Medium', 'later', null],
            ] as [$agent, $title, $why, $size, $status, $note]) {
                Idea::create(['agent' => $agent, 'title' => $title, 'why' => $why, 'size' => $size, 'status' => $status, 'note' => $note]);
            }
        }
    }
}
