<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;

/**
 * Discovers the Teams channels the user belongs to and their file folders.
 */
final class TeamsService
{
    private const GENERAL_NAME = 'General';

    public function __construct(private readonly GraphClient $graph)
    {
    }

    /**
     * Every channel (private ones included) in the user's teams, except each
     * team's primary "General" channel. A team that cannot be read is skipped.
     *
     * @return list<array{teamId: string, teamName: string, channelId: string, name: string}>
     */
    public function channels(): array
    {
        $channels = [];
        foreach ($this->graph->getAll('/me/joinedTeams', ['$select' => 'id,displayName']) as $team) {
            try {
                $channels = [...$channels, ...$this->teamChannels((string)$team['id'], (string)$team['displayName'])];
            } catch (GraphException $e) {
                if ($e->isUnauthorized()) {
                    throw $e;
                }
                Log::warning("Team {$team['id']} skipped: " . $e->getMessage());
            }
        }

        usort($channels, static fn(array $a, array $b): int => [strtolower($a['teamName']), strtolower($a['name'])] <=> [strtolower($b['teamName']), strtolower($b['name'])]);

        return $channels;
    }

    /**
     * @return list<string> Ids of the teams the signed-in user belongs to.
     */
    public function joinedTeamIds(): array
    {
        return array_map(
            static fn (array $team): string => (string)$team['id'],
            $this->graph->getAll('/me/joinedTeams', ['$select' => 'id']),
        );
    }

    /**
     * Channels of the given teams regardless of membership; meant for an
     * app-only token. Unreadable teams are skipped.
     *
     * @param list<string> $teamIds
     * @return list<array{teamId: string, teamName: string, channelId: string, name: string}>
     */
    public function channelsOfTeams(array $teamIds): array
    {
        $channels = [];
        foreach ($teamIds as $teamId) {
            try {
                $channels = [...$channels, ...$this->teamChannels($teamId, '')];
            } catch (GraphException $e) {
                if ($e->isUnauthorized()) {
                    throw $e;
                }
                Log::warning("Team {$teamId} skipped: " . $e->getMessage());
            }
        }

        usort($channels, static fn (array $a, array $b): int => strtolower($a['name']) <=> strtolower($b['name']));

        return $channels;
    }

    /**
     * Drive and folder holding the channel's files.
     *
     * @return array{driveId: string, itemId: string, webUrl: string}
     */
    public function filesFolder(string $teamId, string $channelId): array
    {
        $folder = $this->graph->get('/teams/' . rawurlencode($teamId) . '/channels/' . rawurlencode($channelId) . '/filesFolder');
        $driveId = $folder['parentReference']['driveId'] ?? null;
        if (!isset($folder['id'], $driveId)) {
            throw new GraphException('Channel files folder unavailable.', 404);
        }

        return [
            'driveId' => (string)$driveId,
            'itemId' => (string)$folder['id'],
            'webUrl' => (string)($folder['webUrl'] ?? ''),
        ];
    }

    /**
     * @return list<array{teamId: string, teamName: string, channelId: string, name: string}>
     */
    private function teamChannels(string $teamId, string $teamName): array
    {
        $base = '/teams/' . rawurlencode($teamId);
        $primaryId = $this->primaryChannelId($base);
        $channels = [];
        foreach ($this->graph->getAll($base . '/channels', ['$select' => 'id,displayName,membershipType']) as $channel) {
            $isGeneral = $primaryId !== null
                ? $channel['id'] === $primaryId
                : $channel['displayName'] === self::GENERAL_NAME;
            if (!$isGeneral) {
                $channels[] = [
                    'teamId' => $teamId,
                    'teamName' => $teamName,
                    'channelId' => (string)$channel['id'],
                    'name' => (string)$channel['displayName'],
                    'private' => ($channel['membershipType'] ?? '') === 'private',
                ];
            }
        }

        return $channels;
    }

    private function primaryChannelId(string $teamBase): ?string
    {
        try {
            return $this->graph->get($teamBase . '/primaryChannel', ['$select' => 'id'])['id'] ?? null;
        } catch (GraphException $e) {
            if ($e->isUnauthorized()) {
                throw $e;
            }

            return null;
        }
    }
}
