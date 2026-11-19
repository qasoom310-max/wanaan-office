<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the owner has confirmed. Every agent reads the facts before it answers,
 * so a fact is never asked again. Only the owner creates facts.
 *
 * @property list<string>|null $agents
 */
#[Fillable(['text', 'agents', 'confirmed_by'])]
class Fact extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['agents' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
