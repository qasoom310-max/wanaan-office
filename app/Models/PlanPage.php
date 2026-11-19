<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One page of the website plan.
 *
 * Planned → Approved (the owner) → Being built → Built → Live. When the owner asks for
 * changes it goes to "Changes asked"; when the IT Manager changes an approved page it
 * goes back to Planned, so nothing is built that the owner has not approved as it is.
 *
 * @property Carbon|null $approved_at
 */
#[Fillable(['title', 'path', 'purpose', 'content', 'audience', 'status', 'owner_note', 'sort', 'approved_at'])]
class PlanPage extends Model
{
    public const STATUSES = [
        'planned' => 'Planned',
        'changes' => 'Changes asked',
        'approved' => 'Approved',
        'building' => 'Being built',
        'built' => 'Built',
        'live' => 'Live',
    ];

    public function statusName(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'building', 'built', 'live'], true);
    }
}
