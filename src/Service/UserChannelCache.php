<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Cache\Cache;

/**
 * Remembers each user's committee list and access answers on the server, so a new sign-in shows the
 * overview at once instead of waiting for Graph. The background refresh replaces it right after.
 * It holds channel names and ids only, never tokens, and is keyed by a hash of the user id.
 */
final class UserChannelCache
{
    private const CONFIG = 'portal';

    /**
     * @return array{channels: list<array<string, mixed>>, access: array<string, array{ok: bool, at: int}>}|null
     */
    public function load(string $userId): ?array
    {
        $entry = Cache::read($this->key($userId), self::CONFIG);
        if (!is_array($entry) || !is_array($entry['channels'] ?? null) || !is_array($entry['access'] ?? null)) {
            return null;
        }

        return ['channels' => $entry['channels'], 'access' => $entry['access']];
    }

    /**
     * @param list<array<string, mixed>> $channels
     * @param array<string, array{ok: bool, at: int}> $access
     */
    public function save(string $userId, array $channels, array $access): void
    {
        Cache::write($this->key($userId), ['channels' => $channels, 'access' => $access], self::CONFIG);
    }

    private function key(string $userId): string
    {
        return 'channels_' . hash('sha256', $userId);
    }
}
