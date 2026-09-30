<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\AccessRequestException;
use App\Service\AccessRequestNotifier;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

class AccessRequestNotifierTest extends TestCase
{
    private const URL = 'https://example.test/workflows/abc';

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
     * @param callable(\Psr\Http\Message\RequestInterface): bool|null $match
     */
    private function mockPost(int $status, ?callable $match = null): void
    {
        $options = $match === null ? [] : ['match' => $match];
        $this->http->addMockResponse('POST', self::URL, new Response(["HTTP/1.1 {$status} X"], ''), $options);
    }

    public function testPostsAdaptiveCardWithRequestDetails(): void
    {
        $body = null;
        $this->mockPost(202, static function ($request) use (&$body): bool {
            $body = json_decode((string)$request->getBody(), true);

            return true;
        });

        (new AccessRequestNotifier(self::URL, $this->http))->send('Sam', 'sam@utwente.nl', 'KasCo', 'Treasurer next year');

        $this->assertSame('message', $body['type']);
        $card = $body['attachments'][0];
        $this->assertSame('application/vnd.microsoft.card.adaptive', $card['contentType']);
        $json = json_encode($card['content']);
        foreach (['Sam', 'sam@utwente.nl', 'KasCo', 'Treasurer next year'] as $expected) {
            $this->assertStringContainsString($expected, $json);
        }
    }

    public function testOmitsNoteRowWhenEmpty(): void
    {
        $body = null;
        $this->mockPost(200, static function ($request) use (&$body): bool {
            $body = json_decode((string)$request->getBody(), true);

            return true;
        });

        (new AccessRequestNotifier(self::URL, $this->http))->send('Sam', 'sam@utwente.nl', 'KasCo', '');

        $this->assertStringNotContainsString('Note', json_encode($body));
    }

    public function testNonSuccessStatusThrows(): void
    {
        $this->mockPost(500);

        $this->expectException(AccessRequestException::class);
        (new AccessRequestNotifier(self::URL, $this->http))->send('Sam', 'sam@utwente.nl', 'KasCo', '');
    }

    public function testInsecureUrlIsRejected(): void
    {
        $this->expectException(AccessRequestException::class);
        new AccessRequestNotifier('http://example.test/hook', $this->http);
    }
}
