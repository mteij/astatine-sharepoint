<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;

/**
 * Checks in parallel whether the user can open the files of channels. A team owner can read a
 * private channel's folder metadata without being a member, so the folder is listed as well.
 * Only a 403 counts as no access. A 404 (a channel whose folder was never created) or a timeout
 * or server error keeps the channel visible rather than make it vanish.
 */
final class ParallelFolderProbe
{
    private const BASE_URL = 'https://graph.microsoft.com/v1.0';
    private const BATCH = 10;
    private const TIMEOUT = 10;

    public function __construct(private readonly string $accessToken)
    {
    }

    /**
     * @param list<array{teamId: string, channelId: string}> $channels
     * @return array<string, bool> Channel id => whether its files can be opened.
     * @throws \App\Service\GraphException When the token is rejected.
     */
    public function __invoke(array $channels): array
    {
        $urls = [];
        foreach ($channels as $c) {
            $urls[$c['channelId']] = self::BASE_URL . '/teams/' . rawurlencode($c['teamId'])
                . '/channels/' . rawurlencode($c['channelId']) . '/filesFolder?$select=id,parentReference';
        }

        $answers = [];
        $listings = [];
        foreach ($this->fetch($urls) as $channelId => $response) {
            $driveId = $response['body']['parentReference']['driveId'] ?? null;
            if ($response['status'] === 200 && isset($response['body']['id'], $driveId)) {
                $listings[$channelId] = self::BASE_URL . '/drives/' . rawurlencode((string)$driveId)
                    . '/items/' . rawurlencode((string)$response['body']['id']) . '/children?$select=id&$top=1';
            } else {
                $this->note('filesFolder', $channelId, $response['status']);
                $answers[$channelId] = !$this->isRefusal($response['status']);
            }
        }
        foreach ($this->fetch($listings) as $channelId => $response) {
            $this->note('children', $channelId, $response['status']);
            $answers[$channelId] = !$this->isRefusal($response['status']);
        }

        return $answers;
    }

    private function note(string $step, string $channelId, int $status): void
    {
        if ($status !== 200) {
            Log::info(sprintf('Channel access probe: %s of %s answered %d', $step, $channelId, $status));
        }
    }

    private function isRefusal(int $status): bool
    {
        return $status === 403;
    }

    /**
     * @param array<string, string> $urls
     * @return array<string, array{status: int, body: array<string, mixed>}> Status 0 means no answer.
     */
    private function fetch(array $urls): array
    {
        $results = [];
        foreach (array_chunk($urls, self::BATCH, true) as $batch) {
            $results += $this->fetchBatch($batch);
        }
        foreach ($results as $response) {
            if ($response['status'] === 401) {
                throw new GraphException('Graph rejected the token.', 401);
            }
        }

        return $results;
    }

    /**
     * @param array<string, string> $urls
     * @return array<string, array{status: int, body: array<string, mixed>}>
     */
    private function fetchBatch(array $urls): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->accessToken, 'Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => self::TIMEOUT,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$key] = $handle;
        }

        do {
            $state = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $state === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $handle) {
            $body = json_decode((string)curl_multi_getcontent($handle), true);
            $results[$key] = ['status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => is_array($body) ? $body : []];
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);

        return $results;
    }
}
