<?php

namespace App\Console\Commands;

use App\Jobs\RunAgent;
use App\Models\AgentRun;
use App\Models\Message;
use App\Models\MonthlyReport;
use App\Models\User;
use App\Office\Channels;
use App\Office\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * On the 1st of each month every manager writes its improvement report on the month that
 * just ended. The request is a message in the manager's private chat with the owner, so
 * the owner sees it asked and answered like any other.
 */
#[Signature('office:monthly-reports {--month= : YYYY-MM to report on (default: last month)}')]
#[Description('Ask every manager for its improvement report on last month')]
class MonthlyReports extends Command
{
    public function handle(Team $team, Channels $channels): int
    {
        $owner = User::where('is_owner', true)->orderBy('id')->first();
        if ($owner === null) {
            $this->error('There is no owner yet.');

            return self::FAILURE;
        }

        $month = (string) ($this->option('month') ?: now()->subMonthNoOverflow()->format('Y-m'));
        $name = Carbon::createFromFormat('Y-m', $month)->format('F Y');

        foreach (array_keys($team->all()) as $agent) {
            if (! ($team->get($agent)['manager'] ?? false) || MonthlyReport::where('agent', $agent)->where('month', $month)->exists()) {
                continue;
            }

            $channel = $channels->privateWith($agent);
            $message = Message::create([
                'channel' => $channel,
                'owner_id' => $owner->id,
                'body' => "Monthly report time. Write your improvement report for {$name} ({$month}): read the real data for the whole month first, then save it with office_monthly_report. Reply here with a short summary.",
            ]);
            $run = AgentRun::create(['agent' => $agent, 'channel' => $channel, 'owner_id' => $owner->id, 'message_id' => $message->id, 'status' => AgentRun::WAITING, 'step' => 'Waiting for their turn', 'heartbeat_at' => now()]);

            app()->call([new RunAgent($run), 'handle']);
            $this->info($team->title($agent).': '.(MonthlyReport::where('agent', $agent)->where('month', $month)->exists() ? 'report saved.' : 'answered without saving a report (see the chat).'));
        }

        return self::SUCCESS;
    }
}
