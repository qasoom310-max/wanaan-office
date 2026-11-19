<?php

use App\Jobs\RunAgent;
use Illuminate\Support\Facades\Schedule;

// An agent that stopped moving gets closed with a message, never left hanging.
Schedule::call(fn () => RunAgent::closeAbandoned())->everyMinute()->name('office:close-abandoned-runs')->withoutOverlapping();

// Turns a cut-off web request never started are picked up here, so meetings finish.
Schedule::call(fn () => RunAgent::resumeUnclaimed())->everyMinute()->name('office:resume-turns')->withoutOverlapping();
