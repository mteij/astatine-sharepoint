<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Looks up the channels a signed-in user belongs to; a seam so the dashboard can refresh them.
 */
class ChannelSource
{
    /**
     * @return list<array{teamId: string, teamName: string, channelId: string, name: string}>
     */
    public function forToken(string $accessToken): array
    {
        return (new TeamsService(new GraphClient($accessToken)))->channels();
    }
}
