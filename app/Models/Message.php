<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One chat message. Messages are written once and never changed or deleted:
 * the owner's rule is that chat history must never disappear.
 *
 * Channels: "secretary" and "direct:<agent>" are private to their owner;
 * "everyone" is shared by everyone signed in.
 */
#[Fillable(['channel', 'owner_id', 'user_id', 'agent', 'body'])]
class Message extends Model
{
    public const UPDATED_AT = null;

    public const EVERYONE = 'everyone';

    public const SECRETARY = 'secretary';

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Chat messages are never changed.'));
        static::deleting(fn (): never => throw new LogicException('Chat messages are never deleted.'));
    }

    public static function isPrivate(string $channel): bool
    {
        return $channel !== self::EVERYONE;
    }

    /**
     * The messages of one channel that this user may read.
     *
     * @param  Builder<Message>  $query
     * @return Builder<Message>
     */
    public function scopeVisibleIn(Builder $query, string $channel, User $user): Builder
    {
        $query->where('channel', $channel);

        return self::isPrivate($channel) ? $query->where('owner_id', $user->id) : $query->whereNull('owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFromAgent(): bool
    {
        return $this->agent !== null;
    }
}
