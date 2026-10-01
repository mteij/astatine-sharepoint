<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\AccessRequest;
use App\Service\AccessRequestException;
use App\Service\GraphClient;
use App\Service\SharePointAccessRequests;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

class SharePointAccessRequestsTest extends TestCase
{
    private const SITE = 'https://astatine85.sharepoint.com/sites/S.A.Astatine-Board';
    private const ITEMS_URL = 'https://graph.microsoft.com/v1.0/sites/astatine85.sharepoint.com:/sites/S.A.Astatine-Board:/lists/Committee%20Requests/items';

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

    private function requests(string $site = self::SITE): SharePointAccessRequests
    {
        return new SharePointAccessRequests(fn (): GraphClient => new GraphClient('app', $this->http), $site, 'Committee Requests');
    }

    private function request(string $note = ''): AccessRequest
    {
        return new AccessRequest('Sam', 'sam@utwente.nl', 'u-1', 'KasCo', 't-1', 'c-1', $note);
    }

    public function testAddCreatesListItemWithRequestFields(): void
    {
        $body = null;
        $this->http->addMockResponse('POST', self::ITEMS_URL, new Response(['HTTP/1.1 201 Created'], '{}'), [
            'match' => static function ($request) use (&$body): bool {
                $body = json_decode((string)$request->getBody(), true);

                return true;
            },
        ]);

        $this->requests()->add($this->request('Hello'));

        $this->assertSame([
            'fields' => [
                'Title' => 'sam@utwente.nl',
                'Name' => 'Sam',
                'Committee' => 'KasCo',
                'Note' => 'Hello',
                'UserId' => 'u-1',
                'TeamId' => 't-1',
                'ChannelId' => 'c-1',
                'Status' => 'Pending',
            ],
        ], $body);
    }

    public function testGraphFailureBecomesAccessRequestException(): void
    {
        $this->http->addMockResponse(
            'POST',
            self::ITEMS_URL,
            new Response(['HTTP/1.1 403 Forbidden', 'Content-Type: application/json'], '{"error":{"message":"Denied"}}'),
        );

        $this->expectException(AccessRequestException::class);
        $this->requests()->add($this->request());
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function mockItems(array $items, ?string &$query = null, int $status = 200): void
    {
        $this->http->addMockResponse(
            'GET',
            self::ITEMS_URL . '/*',
            new Response(["HTTP/1.1 {$status} X", 'Content-Type: application/json'], json_encode(['value' => $items])),
            ['match' => static function ($request) use (&$query): bool {
                $query = rawurldecode($request->getUri()->getQuery());

                return true;
            }],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function item(?string $status, string $modified = 'now', string $created = 'now'): array
    {
        return [
            'createdDateTime' => gmdate('c', strtotime($created)),
            'lastModifiedDateTime' => gmdate('c', strtotime($modified)),
            'fields' => $status === null ? [] : ['Status' => $status],
        ];
    }

    public function testPendingRequestBlocksAnotherOne(): void
    {
        $this->mockItems([$this->item('Pending')]);

        $this->assertTrue($this->requests()->hasOpenRequest('sam@utwente.nl', 'KasCo'));
    }

    public function testItemWithoutStatusCountsAsPending(): void
    {
        $this->mockItems([$this->item(null)]);

        $this->assertTrue($this->requests()->hasOpenRequest('sam@utwente.nl', 'KasCo'));
    }

    public function testAbandonedPendingRequestStopsBlocking(): void
    {
        // A flow run cannot wait longer than 30 days, so an older Pending item was never going to be answered.
        $this->mockItems([$this->item('Pending', '-40 days', '-40 days')]);

        $this->assertFalse($this->requests()->hasOpenRequest('sam@utwente.nl', 'KasCo'));
    }

    public function testExpiredRequestDoesNotBlock(): void
    {
        $this->mockItems([$this->item('Expired')]);

        $this->assertFalse($this->requests()->hasOpenRequest('sam@utwente.nl', 'KasCo'));
    }

    public function testRecentlyDeniedRequestBlocks(): void
    {
        $this->mockItems([$this->item('Denied', '-1 day')]);

        $this->assertTrue($this->requests()->hasOpenRequest('sam@utwente.nl', 'KasCo'));
    }

    public function testOldDeniedAndApprovedRequestsDoNotBlock(): void
    {
        $this->mockItems([$this->item('Denied', '-30 days'), $this->item('Approved')]);

        $this->assertFalse($this->requests()->hasOpenRequest('sam@utwente.nl', 'KasCo'));
    }

    public function testNoRequestsMeansNotBlocked(): void
    {
        $this->mockItems([]);

        $this->assertFalse($this->requests()->hasOpenRequest('sam@utwente.nl', 'KasCo'));
    }

    public function testLookupFiltersByEmailAndCommitteeAndEscapesQuotes(): void
    {
        $query = null;
        $this->mockItems([], $query);

        $this->requests()->hasOpenRequest("o'neil@utwente.nl", "Bob's");

        $this->assertStringContainsString("fields/Title eq 'o''neil@utwente.nl'", $query);
        $this->assertStringContainsString("fields/Committee eq 'Bob''s'", $query);
    }

    public function testLookupFailureBecomesAccessRequestException(): void
    {
        $this->mockItems([], $query, 403);

        $this->expectException(AccessRequestException::class);
        $this->requests()->hasOpenRequest('sam@utwente.nl', 'KasCo');
    }

    /**
     * @dataProvider badSites
     */
    public function testRejectsSiteThatIsNotASharePointSiteUrl(string $site): void
    {
        $this->expectException(AccessRequestException::class);
        $this->requests($site)->add($this->request());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badSites(): array
    {
        return [
            'http' => ['http://astatine85.sharepoint.com/sites/x'],
            'other host' => ['https://evil.test/sites/x'],
            'no path' => ['https://astatine85.sharepoint.com'],
        ];
    }
}
