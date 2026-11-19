<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Proof that an agent read a message. Kept for good, like the messages themselves.
 */
#[Fillable(['message_id', 'agent'])]
class MessageRead extends Model
{
    public const UPDATED_AT = null;

    public static function mark(int $messageId, string $agent): void
    {
        self::firstOrCreate(['message_id' => $messageId, 'agent' => $agent]);
    }
}
