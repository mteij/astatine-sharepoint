<?php

declare(strict_types=1);

namespace App\Service;

use Cake\Http\Client;
use Cake\Log\Log;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Thin Microsoft Graph v1.0 client authenticated with a delegated user token.
 * New endpoints belong in small services built on top of this class.
 */
final class GraphClient
{
    private const BASE_URL = 'https://graph.microsoft.com/v1.0';

    private readonly Client $http;

    public function __construct(private readonly string $accessToken, ?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 10]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request(self::BASE_URL . $path, $query);
    }

    /**
     * GET a collection and follow `@odata.nextLink` until exhausted.
     *
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function getAll(string $path, array $query = []): array
    {
        $items = [];
        $url = self::BASE_URL . $path;
        do {
            $page = $this->request($url, $query);
            $items = [...$items, ...($page['value'] ?? [])];
            $url = $page['@odata.nextLink'] ?? null;
            if ($url !== null && !str_starts_with($url, self::BASE_URL)) {
                throw new GraphException('Unexpected pagination link.', 502);
            }
            $query = [];
        } while ($url !== null);

        return $items;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function request(string $url, array $query): array
    {
        $started = hrtime(true);
        try {
            $response = $this->http->get($url, $query, [
                'headers' => ['Authorization' => 'Bearer ' . $this->accessToken],
            ]);
        } catch (ClientExceptionInterface $e) {
            throw new GraphException('Graph request failed: ' . $e->getMessage(), 0);
        } finally {
            Log::debug(sprintf('Graph GET %s took %d ms', strtok($url, '?'), (hrtime(true) - $started) / 1e6));
        }

        $body = (array)$response->getJson();
        if (!$response->isOk()) {
            throw new GraphException(
                (string)($body['error']['message'] ?? $response->getReasonPhrase()),
                $response->getStatusCode(),
            );
        }

        return $body;
    }
}
