<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An agent's own name. Set once by the owner and locked; only the command line
 * (php artisan office:unlock-name) can free it again.
 */
#[Fillable(['agent', 'name', 'named_by'])]
class AgentName extends Model {}
