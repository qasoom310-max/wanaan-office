<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Office\Erp\ErpProblem;
use App\Office\Seo\SeoClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Connect the office to the SEO system's read-only office API. The key is read from the
 * OFFICE_SEO_KEY environment variable (or asked for, hidden), never from the command line.
 *
 *   OFFICE_SEO_KEY=seo_office_… php artisan office:connect-seo https://seo.wanaan-bh.com
 */
#[Signature('office:connect-seo {url : the SEO system address}')]
#[Description('Connect the office to the SEO system (read-only) and list the projects it may read')]
class ConnectSeo extends Command
{
    public function handle(SeoClient $seo): int
    {
        $key = getenv('OFFICE_SEO_KEY') ?: $this->secret('SEO office key (from: php artisan office:key create … on the SEO system)');

        if (! is_string($key) || ! str_starts_with($key, 'seo_office_')) {
            $this->error('That is not an SEO office key (it starts with seo_office_).');

            return self::FAILURE;
        }

        Setting::write(SeoClient::URL, rtrim((string) $this->argument('url'), '/'));
        Setting::write(SeoClient::KEY, $key);

        try {
            foreach ($seo->get('projects')['data'] ?? [] as $project) {
                $this->info("Project {$project['id']}: {$project['name']} answers.");
            }
        } catch (ErpProblem $problem) {
            $this->error(ucfirst($problem->getMessage()).'.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
