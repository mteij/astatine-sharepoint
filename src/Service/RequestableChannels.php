<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Channels a member may ask the board for access to: only allow-listed ones, and only those they are not in yet.
 */
final class RequestableChannels
{
    /**
     * @param list<string> $allowedChannelIds Channels that may be requested; an empty list allows nothing,
     *   so a new or renamed confidential channel is never offered.
     * @param list<string> $teamIds Teams to look in; empty means every team the user belongs to.
     */
    public function __construct(
        private readonly array $allowedChannelIds,
        private readonly array $teamIds,
    ) {
    }

    /**
     * Allow-listed channels of the requestable teams, listed with the app-only token.
     *
     * @return list<array{teamId: string, teamName: string, channelId: string, name: string}>
     */
    public function fetch(string $userToken, string $appToken): array
    {
        $teamIds = $this->teamIds !== []
            ? $this->teamIds
            : (new TeamsService(new GraphClient($userToken)))->joinedTeamIds();
        if ($teamIds === []) {
            return [];
        }

        return $this->allowListed((new TeamsService(new GraphClient($appToken)))->channelsOfTeams($teamIds));
    }

    /**
     * @param array<int, array{teamId: string, teamName: string, channelId: string, name: string}> $channels
     * @return list<array{teamId: string, teamName: string, channelId: string, name: string}>
     */
    public function allowListed(array $channels): array
    {
        return array_values(array_filter(
            $channels,
            fn (array $c): bool => in_array($c['channelId'], $this->allowedChannelIds, true),
        ));
    }

    /**
     * The allow-listed channels the user is not in, keyed by channel id.
     *
     * @param array<int, array{teamId: string, teamName: string, channelId: string, name: string}> $channels
     * @param list<string> $joinedChannelIds
     * @return array<string, array{teamId: string, teamName: string, channelId: string, name: string}>
     */
    public function notJoined(array $channels, array $joinedChannelIds): array
    {
        $options = [];
        foreach ($this->allowListed($channels) as $channel) {
            if (!in_array($channel['channelId'], $joinedChannelIds, true)) {
                $options[$channel['channelId']] = $channel;
            }
        }

        return $options;
    }
}
