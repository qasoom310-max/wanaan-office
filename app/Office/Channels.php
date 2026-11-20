<?php

namespace App\Office;

use App\Models\Idea;
use App\Models\Message;

/**
 * The chats: the private Secretary chat, the shared Everyone chat, and a private
 * direct chat with each agent ("direct:<agent>"), and the discussion on each IT board
 * request ("idea:<id>") between the owner and the agent who asked.
 */
class Channels
{
    public function __construct(private Team $team) {}

    public function isValid(string $channel): bool
    {
        if (in_array($channel, [Message::SECRETARY, Message::EVERYONE], true)) {
            return true;
        }

        if (self::ideaId($channel) !== null) {
            return Idea::whereKey(self::ideaId($channel))->exists();
        }

        return str_starts_with($channel, 'direct:') && $this->team->exists(substr($channel, 7));
    }

    public static function idea(int $id): string
    {
        return 'idea:'.$id;
    }

    /** The IT board request a discussion is about, or null when it is another chat. */
    public static function ideaId(string $channel): ?int
    {
        return preg_match('/^idea:(\d+)$/', $channel, $match) === 1 ? (int) $match[1] : null;
    }

    public function direct(string $agent): string
    {
        return 'direct:'.$agent;
    }

    /** The private chat with an agent: the Secretary keeps her own, every other agent its direct chat. */
    public function privateWith(string $agent): string
    {
        return $agent === 'sec' ? Message::SECRETARY : $this->direct($agent);
    }

    /**
     * Who answers a new message: the Secretary in her chat, the agent in its direct
     * chat, and in Everyone the agent mentioned with @, else the Secretary.
     */
    public function answererFor(string $channel, string $text): string
    {
        return match (true) {
            str_starts_with($channel, 'direct:') => substr($channel, 7),
            self::ideaId($channel) !== null => Idea::find(self::ideaId($channel))?->agent ?? 'it',
            $channel === Message::EVERYONE => $this->team->mentionedIn($text) ?? 'sec',
            default => 'sec',
        };
    }

    public function title(string $channel): string
    {
        return match (true) {
            str_starts_with($channel, 'direct:') => $this->team->label(substr($channel, 7)),
            self::ideaId($channel) !== null => 'IT request: '.(Idea::find(self::ideaId($channel))?->title ?? ''),
            $channel === Message::EVERYONE => 'Everyone',
            default => $this->team->label('sec'),
        };
    }
}
