<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Drops private channels the signed-in user cannot open. A team owner is listed every private
 * channel of the team, but Graph refuses the files of those they are not a member of.
 *
 * Answers are kept per channel so each page load only checks channels it has not seen: an
 * accessible channel stays accessible for the session, a refused one is checked again after
 * REFUSED_TTL seconds so newly granted access shows up.
 */
final class ChannelAccessFilter
{
    private const REFUSED_TTL = 300;

    /**
     * @param callable(list<array{teamId: string, channelId: string}>): array<string, bool> $probe
     *        Maps channel id to whether its files can be opened.
     */
    public function __construct(private readonly mixed $probe)
    {
    }

    /**
     * @param list<array{teamId: string, teamName: string, channelId: string, name: string, private?: bool}> $channels
     * @param array<string, array{ok: bool, at: int}> $known Answers from earlier calls.
     * @return array{0: list<array{teamId: string, teamName: string, channelId: string, name: string, private?: bool}>, 1: array<string, array{ok: bool, at: int}>}
     */
    public function filter(array $channels, array $known, int $now): array
    {
        $unknown = [];
        foreach ($channels as $channel) {
            if (($channel['private'] ?? false) && !$this->isFresh($known[$channel['channelId']] ?? null, $now)) {
                $unknown[] = ['teamId' => $channel['teamId'], 'channelId' => $channel['channelId']];
            }
        }

        $answers = $unknown === [] ? [] : ($this->probe)($unknown);
        foreach ($answers as $channelId => $ok) {
            $known[$channelId] = ['ok' => $ok, 'at' => $now];
        }

        $visible = array_values(array_filter(
            $channels,
            static fn (array $c): bool => !($c['private'] ?? false) || ($known[$c['channelId']]['ok'] ?? true),
        ));

        return [$visible, $known];
    }

    /**
     * Records that Graph refused a channel the user was shown.
     *
     * @param array<string, array{ok: bool, at: int}> $known
     * @return array<string, array{ok: bool, at: int}>
     */
    public function refused(array $known, string $channelId, int $now): array
    {
        return [...$known, $channelId => ['ok' => false, 'at' => $now]];
    }

    /**
     * @param array{ok: bool, at: int}|null $entry
     */
    private function isFresh(?array $entry, int $now): bool
    {
        return $entry !== null && ($entry['ok'] || $now - $entry['at'] < self::REFUSED_TTL);
    }
}
