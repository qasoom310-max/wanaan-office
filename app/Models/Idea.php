<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A request to IT. Nothing is built until the owner approves it.
 */
#[Fillable(['agent', 'title', 'why', 'size', 'status', 'note', 'decided_by', 'decided_at'])]
class Idea extends Model
{
    public const STATUSES = ['wait' => 'Waiting for you', 'yes' => 'Approved', 'later' => 'Later', 'done' => 'Done'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
