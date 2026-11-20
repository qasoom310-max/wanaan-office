<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A request to IT. Nothing is built until the owner approves it.
 */
#[Fillable(['agent', 'title', 'why', 'size', 'status', 'note', 'decided_by', 'decided_at', 'requirement', 'done_at', 'owner_reason'])]
class Idea extends Model
{
    /** The board's sections, in the order they are shown. */
    public const STATUSES = [
        'wait' => 'Waiting for you',
        'rethink' => 'Sent back to rethink',
        'yes' => 'Writing the requirement',
        'ready' => 'Ready to build',
        'building' => 'Being built',
        'done' => 'Done',
        'later' => 'Not now',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'done_at' => 'datetime'];
    }

    /** The owner and the agent who asked can still talk it over: it is not approved yet. */
    public function isOpenForDiscussion(): bool
    {
        return in_array($this->status, ['wait', 'rethink', 'later'], true);
    }

    /** What to paste into Claude Code to build it. */
    public function forClaudeCode(): string
    {
        return "Build this for Wanaan (approved by the owner on the AI office's IT board).\n\n"
            ."Request: {$this->title}\nWhy: {$this->why}\nSize: {$this->size}\n\n"
            ."Requirement written by the IT Manager:\n".($this->requirement ?? '(not written yet)');
    }
}
