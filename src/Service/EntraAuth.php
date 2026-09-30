<?php

declare(strict_types=1);

namespace App\Service;

use Cake\Core\Configure;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use RuntimeException;

/**
 * Microsoft Entra ID (v2.0) authorization-code flow with PKCE.
 */
final class EntraAuth
{
    public const SCOPES = ['openid', 'profile', 'User.Read', 'Team.ReadBasic.All', 'Channel.ReadBasic.All', 'Files.Read.All'];

    private readonly AbstractProvider $provider;

    public function __construct(?AbstractProvider $provider = null)
    {
        $this->provider = $provider ?? self::buildProvider((array)Configure::read('Entra'));
    }

    /**
     * @return array{url: string, state: string, pkce: string}
     */
    public function authorizationRequest(): array
    {
        $url = $this->provider->getAuthorizationUrl(['scope' => self::SCOPES]);

        return [
            'url' => $url,
            'state' => $this->provider->getState(),
            'pkce' => (string)$this->provider->getPkceCode(),
        ];
    }

    public function exchange(string $code, string $pkceVerifier): AccessTokenInterface
    {
        $this->provider->setPkceCode($pkceVerifier);

        return $this->provider->getAccessToken('authorization_code', ['code' => $code]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function buildProvider(array $config): GenericProvider
    {
        foreach (['clientId', 'clientSecret', 'tenantId', 'redirectUri'] as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException("Missing Entra configuration: {$key}");
            }
        }

        $base = 'https://login.microsoftonline.com/' . rawurlencode((string)$config['tenantId']) . '/oauth2/v2.0';

        return new GenericProvider([
            'clientId' => $config['clientId'],
            'clientSecret' => $config['clientSecret'],
            'redirectUri' => $config['redirectUri'],
            'urlAuthorize' => $base . '/authorize',
            'urlAccessToken' => $base . '/token',
            'urlResourceOwnerDetails' => 'https://graph.microsoft.com/v1.0/me',
            'scopeSeparator' => ' ',
            'pkceMethod' => AbstractProvider::PKCE_METHOD_S256,
        ]);
    }
}
