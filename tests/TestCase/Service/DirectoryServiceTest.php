<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\DirectoryService;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

class DirectoryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Client::clearMockResponses();
        parent::tearDown();
    }

    private function mockMe(array $body): void
    {
        Client::addMockResponse(
            'GET',
            'https://graph.microsoft.com/v1.0/me/*',
            new Response(['HTTP/1.1 200 OK', 'Content-Type: application/json'], json_encode($body)),
            ['match' => static fn($request): bool => $request->getUri()->getPath() === '/v1.0/me'],
        );
    }

    public function testProfileUsesMailAddress(): void
    {
        $this->mockMe(['id' => 'u1', 'displayName' => 'Sam', 'mail' => 'sam@astatine.nl', 'userPrincipalName' => 'sam@student.utwente.nl']);

        $this->assertSame(
            ['id' => 'u1', 'name' => 'Sam', 'email' => 'sam@astatine.nl'],
            (new DirectoryService())->profile('token'),
        );
    }

    public function testProfileFallsBackToUserPrincipalName(): void
    {
        $this->mockMe(['id' => 'u1', 'displayName' => 'Sam', 'mail' => null, 'userPrincipalName' => 'sam@student.utwente.nl']);

        $this->assertSame('sam@student.utwente.nl', (new DirectoryService())->profile('token')['email']);
    }
}
