<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something an agent needs the owner to answer before it can work well.
 * An answer becomes a fact.
 *
 * @property Carbon|null $answered_at
 */
#[Fillable(['agent', 'question', 'why', 'answer', 'answered_by', 'answered_at', 'fact_id'])]
class Question extends Model
{
    /** Open questions an agent may have at once, so the page stays readable. */
    public const OPEN_PER_AGENT = 3;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->answered_at === null;
    }
}
