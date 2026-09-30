<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Utility\Text;

/**
 * The user's channels with stable, unique URL slugs.
 */
final class ChannelCatalog
{
    /**
     * @var array<string, \App\Service\Channel>
     */
    private readonly array $bySlug;

    /**
     * @param list<array{teamId: string, teamName: string, channelId: string, name: string}> $channels
     */
    public function __construct(array $channels)
    {
        $bySlug = [];
        foreach ($channels as $channel) {
            $base = strtolower(Text::slug($channel['name'])) ?: 'channel';
            $slug = $base;
            for ($n = 2; isset($bySlug[$slug]); $n++) {
                $slug = "{$base}-{$n}";
            }
            $bySlug[$slug] = new Channel($slug, $channel['name'], $channel['teamName'], $channel['teamId'], $channel['channelId']);
        }
        $this->bySlug = $bySlug;
    }

    /**
     * @return list<\App\Service\Channel>
     */
    public function all(): array
    {
        return array_values($this->bySlug);
    }

    public function find(string $slug): ?Channel
    {
        return $this->bySlug[$slug] ?? null;
    }

    public function spansMultipleTeams(): bool
    {
        return count(array_unique(array_map(static fn(Channel $c): string => $c->teamId, $this->bySlug))) > 1;
    }
}
