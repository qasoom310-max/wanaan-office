<?php

namespace App\Console\Commands;

use App\Models\AgentName;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Names are permanent on the page. Only someone with access to the server can free one,
 * for example to fix a typo the owner asked to fix.
 */
#[Signature('office:unlock-name {agent}')]
#[Description('Free an agent\'s permanent name so the owner can set it again')]
class UnlockName extends Command
{
    public function handle(): int
    {
        $name = AgentName::where('agent', $this->argument('agent'))->first();

        if ($name === null) {
            $this->error('That agent has no name yet.');

            return self::FAILURE;
        }

        $this->info("Freed \"{$name->name}\". The owner can name the {$this->argument('agent')} agent again.");
        $name->delete();

        return self::SUCCESS;
    }
}
