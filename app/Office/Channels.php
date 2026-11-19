<?php

namespace App\Office;

use App\Models\Message;

/**
 * The chats: the private Secretary chat, the shared Everyone chat, and a private
 * direct chat with each agent ("direct:<agent>").
 */
class Channels
{
    public function __construct(private Team $team) {}

    public function isValid(string $channel): bool
    {
        if (in_array($channel, [Message::SECRETARY, Message::EVERYONE], true)) {
            return true;
        }

        return str_starts_with($channel, 'direct:') && $this->team->exists(substr($channel, 7));
    }

    public function direct(string $agent): string
    {
        return 'direct:'.$agent;
    }

    /**
     * Who answers a new message: the Secretary in her chat, the agent in its direct
     * chat, and in Everyone the agent mentioned with @, else the Secretary.
     */
    public function answererFor(string $channel, string $text): string
    {
        return match (true) {
            str_starts_with($channel, 'direct:') => substr($channel, 7),
            $channel === Message::EVERYONE => $this->team->mentionedIn($text) ?? 'sec',
            default => 'sec',
        };
    }

    public function title(string $channel): string
    {
        return match (true) {
            str_starts_with($channel, 'direct:') => $this->team->label(substr($channel, 7)),
            $channel === Message::EVERYONE => 'Everyone',
            default => $this->team->label('sec'),
        };
    }
}
