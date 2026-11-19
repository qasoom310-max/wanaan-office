<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Office\Erp\ErpClient;
use App\Office\Erp\ErpProblem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Connect the office to the ERP's read-only office API. The key is read from the
 * OFFICE_ERP_KEY environment variable (or asked for, hidden), never from the command
 * line, so it does not land in shell history.
 *
 *   OFFICE_ERP_KEY=office_… php artisan office:connect-erp https://erp.wanaan-bh.com --workspace=1 --workspace=4
 */
#[Signature('office:connect-erp {url : the ERP address} {--workspace=* : an ERP workspace id the key may read; repeat for more}')]
#[Description('Connect the office to the ERP (read-only) and check that every company answers')]
class ConnectErp extends Command
{
    public function handle(ErpClient $erp): int
    {
        $key = getenv('OFFICE_ERP_KEY') ?: $this->secret('ERP office key (from: php artisan office:key create … on the ERP)');
        $ids = array_filter((array) $this->option('workspace'), 'ctype_digit');

        if (! is_string($key) || ! str_starts_with($key, 'office_')) {
            $this->error('That is not an ERP office key (it starts with office_).');

            return self::FAILURE;
        }
        if ($ids === []) {
            $this->error('Name at least one --workspace the key may read.');

            return self::FAILURE;
        }

        Setting::write(ErpClient::URL, rtrim((string) $this->argument('url'), '/'));
        Setting::write(ErpClient::KEY, $key);
        Setting::write(ErpClient::WORKSPACES, implode(',', $ids));

        $failed = false;
        foreach ($erp->workspaceIds() as $id) {
            try {
                $info = $erp->get('workspace', $id);
                $this->info("Company {$id}: {$info['name']} answers (accounting ".(($info['accounting'] ?? false) ? 'on' : 'off').').');
            } catch (ErpProblem $problem) {
                $this->error("Company {$id}: {$problem->getMessage()}.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
