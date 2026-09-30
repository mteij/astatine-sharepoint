<?php

declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\EntraAuth;
use Cake\TestSuite\TestCase;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Token\AccessToken;

class EntraAuthAppTokenTest extends TestCase
{
    public function testAppAccessTokenUsesClientCredentialsWithGraphDefaultScope(): void
    {
        $provider = $this->createMock(AbstractProvider::class);
        $provider->expects($this->once())
            ->method('getAccessToken')
            ->with('client_credentials', ['scope' => 'https://graph.microsoft.com/.default'])
            ->willReturn(new AccessToken(['access_token' => 'app-token']));

        $this->assertSame('app-token', (new EntraAuth($provider))->appAccessToken());
    }
}
