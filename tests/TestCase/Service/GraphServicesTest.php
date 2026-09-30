<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\DriveService;
use App\Service\FolderPath;
use App\Service\GraphClient;
use App\Service\GraphException;
use App\Service\TeamsService;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

class GraphServicesTest extends TestCase
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    private Client $http;

    protected function setUp(): void
    {
        parent::setUp();
        Client::clearMockResponses();
        $this->http = new Client();
    }

    protected function tearDown(): void
    {
        Client::clearMockResponses();
        parent::tearDown();
    }

    /**
     * Mocks a GET by scheme, host and path. `$query` pins the exact query string
     * when several mocks share a path (null accepts any query).
     */
    private function mock(string $url, array $body, int $status = 200, ?string $query = null): void
    {
        $response = new Response(
            ["HTTP/1.1 {$status} X", 'Content-Type: application/json'],
            json_encode($body),
        );
        $path = parse_url($url, PHP_URL_PATH);
        $this->http->addMockResponse('GET', $url . '/*', $response, [
            'match' => static fn($request): bool => rawurldecode($request->getUri()->getPath()) === rawurldecode($path)
                && ($query === null || $request->getUri()->getQuery() === $query),
        ]);
    }

    private function graph(): GraphClient
    {
        return new GraphClient('token', $this->http);
    }

    public function testGetAllFollowsNextLink(): void
    {
        $this->mock(self::BASE . '/things', ['value' => [['id' => 1]], '@odata.nextLink' => self::BASE . '/things?page=2'], 200, '');
        $this->mock(self::BASE . '/things', ['value' => [['id' => 2]]], 200, 'page=2');

        $this->assertSame([['id' => 1], ['id' => 2]], $this->graph()->getAll('/things'));
    }

    public function testGraphErrorBecomesException(): void
    {
        $this->mock(self::BASE . '/me', ['error' => ['message' => 'Expired']], 401);

        try {
            $this->graph()->get('/me');
            $this->fail('Expected GraphException');
        } catch (GraphException $e) {
            $this->assertTrue($e->isUnauthorized());
            $this->assertSame('Expired', $e->getMessage());
        }
    }

    public function testOffBaseNextLinkFailsInsteadOfTruncating(): void
    {
        $this->mock(self::BASE . '/things', ['value' => [['id' => 1]], '@odata.nextLink' => 'https://evil.test/next']);

        $this->expectException(GraphException::class);
        $this->graph()->getAll('/things');
    }

    public function testTransportFailureBecomesGraphException(): void
    {
        // No mock registered: the adapter throws a client exception.
        $this->expectException(GraphException::class);
        $this->graph()->get('/me');
    }

    public function testNonJsonErrorBodyStillYieldsGraphException(): void
    {
        $response = new Response(['HTTP/1.1 502 Bad Gateway'], '<html>oops</html>');
        $this->http->addMockResponse('GET', self::BASE . '/me/*', $response);

        try {
            $this->graph()->get('/me');
            $this->fail('Expected GraphException');
        } catch (GraphException $e) {
            $this->assertSame(502, $e->status());
        }
    }

    public function testChannelsExcludePrimaryAndSkipUnreadableTeams(): void
    {
        $this->mock(self::BASE . '/me/joinedTeams', ['value' => [
            ['id' => 't1', 'displayName' => 'Astatine'],
            ['id' => 't2', 'displayName' => 'Locked'],
        ]]);
        $this->mock(self::BASE . '/teams/t1/primaryChannel', ['id' => 'c-general']);
        $this->mock(self::BASE . '/teams/t1/channels', ['value' => [
            ['id' => 'c-general', 'displayName' => 'Algemeen'],
            ['id' => 'c-kasco', 'displayName' => 'KasCo'],
            ['id' => 'c-atac', 'displayName' => 'ATAC'],
        ]]);
        $this->mock(self::BASE . '/teams/t2/primaryChannel', ['error' => ['message' => 'Forbidden']], 403);
        $this->mock(self::BASE . '/teams/t2/channels', ['error' => ['message' => 'Forbidden']], 403);

        $channels = (new TeamsService($this->graph()))->channels();

        $this->assertSame(['ATAC', 'KasCo'], array_column($channels, 'name'));
        $this->assertSame('Astatine', $channels[0]['teamName']);
    }

    public function testChannelsOfTeamsListsEveryChannelExceptGeneral(): void
    {
        $this->mock(self::BASE . '/teams/t1/primaryChannel', ['id' => 'c-general']);
        $this->mock(self::BASE . '/teams/t1/channels', ['value' => [
            ['id' => 'c-general', 'displayName' => 'Algemeen'],
            ['id' => 'c-kasco', 'displayName' => 'KasCo'],
        ]]);
        $this->mock(self::BASE . '/teams/t2/primaryChannel', ['error' => ['message' => 'Forbidden']], 403);
        $this->mock(self::BASE . '/teams/t2/channels', ['error' => ['message' => 'Forbidden']], 403);

        $channels = (new TeamsService($this->graph()))->channelsOfTeams(['t1', 't2']);

        $this->assertSame(['KasCo'], array_column($channels, 'name'));
        $this->assertSame('c-kasco', $channels[0]['channelId']);
    }

    public function testChannelsFallBackToGeneralNameWithoutPrimary(): void
    {
        $this->mock(self::BASE . '/me/joinedTeams', ['value' => [['id' => 't1', 'displayName' => 'Astatine']]]);
        $this->mock(self::BASE . '/teams/t1/primaryChannel', ['error' => ['message' => 'Nope']], 404);
        $this->mock(self::BASE . '/teams/t1/channels', ['value' => [
            ['id' => 'a', 'displayName' => 'General'],
            ['id' => 'b', 'displayName' => 'KasCo'],
        ]]);

        $this->assertSame(['KasCo'], array_column((new TeamsService($this->graph()))->channels(), 'name'));
    }

    public function testExpiredTokenDuringDiscoveryPropagates(): void
    {
        $this->mock(self::BASE . '/me/joinedTeams', ['error' => ['message' => 'Expired']], 401);

        $this->expectException(GraphException::class);
        (new TeamsService($this->graph()))->channels();
    }

    public function testFilesFolderReturnsDriveAndItem(): void
    {
        $this->mock(self::BASE . '/teams/t1/channels/19%3Aabc%40thread.tacv2/filesFolder', [
            'id' => 'item-1', 'parentReference' => ['driveId' => 'drive-1'], 'webUrl' => 'https://x.sharepoint.com/ATAC',
        ]);

        $folder = (new TeamsService($this->graph()))->filesFolder('t1', '19:abc@thread.tacv2');

        $this->assertSame(
            ['driveId' => 'drive-1', 'itemId' => 'item-1', 'webUrl' => 'https://x.sharepoint.com/ATAC'],
            $folder,
        );
    }

    public function testFilesFolderWithoutDriveIsNotFound(): void
    {
        $this->mock(self::BASE . '/teams/t1/channels/c1/filesFolder', ['id' => 'item-1']);

        try {
            (new TeamsService($this->graph()))->filesFolder('t1', 'c1');
            $this->fail('Expected GraphException');
        } catch (GraphException $e) {
            $this->assertTrue($e->isNotFound());
        }
    }

    public function testListSortsFoldersFirstThenByName(): void
    {
        $this->mock(self::BASE . '/drives/d1/items/f1/children', ['value' => [
            ['name' => 'b.pdf', 'size' => 10, 'webUrl' => 'u1', 'lastModifiedDateTime' => '2026-01-02T10:00:00Z'],
            ['name' => 'Zeta', 'folder' => ['childCount' => 1], 'webUrl' => 'u2'],
            ['name' => 'a.pdf', 'size' => 5, 'webUrl' => 'u3'],
            ['name' => 'alpha', 'folder' => ['childCount' => 0], 'webUrl' => 'u4'],
        ]]);

        $items = (new DriveService($this->graph()))->list('d1', 'f1', FolderPath::root());

        $this->assertSame(['alpha', 'Zeta', 'a.pdf', 'b.pdf'], array_map(fn($i) => $i->name, $items));
        $this->assertTrue($items[0]->isFolder);
        $this->assertSame('2026-01-02', $items[3]->modified->format('Y-m-d'));
    }

    public function testListUsesPathAddressingForSubfolders(): void
    {
        $this->mock(self::BASE . '/drives/d1/items/f1:/Reports/My%20Folder:/children', ['value' => []]);

        $items = (new DriveService($this->graph()))
            ->list('d1', 'f1', FolderPath::fromSegments(['Reports', 'My Folder']));

        $this->assertSame([], $items);
    }
}
