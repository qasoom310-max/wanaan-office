<?php

namespace App\Providers;

use App\Models\Setting;
use App\Models\User;
use App\Office\AgentModel;
use App\Office\ClaudeAgentModel;
use App\Office\Team;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Team::class);

        // The key entered on the Settings page wins over the server's .env.
        $this->app->bind(AgentModel::class, fn (): AgentModel => new ClaudeAgentModel(
            Setting::read(Setting::ANTHROPIC_API_KEY) ?? config('services.anthropic.key'),
        ));
    }

    public function boot(): void
    {
        Gate::define('owner', fn (User $user): bool => $user->isOwner());
    }
}
