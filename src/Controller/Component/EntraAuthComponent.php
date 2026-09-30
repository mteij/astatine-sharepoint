<?php

declare(strict_types=1);

namespace App\Controller\Component;

use App\Service\ChannelCatalog;
use App\Service\DirectoryService;
use App\Service\EntraAuth;
use App\Service\GraphClient;
use App\Service\GraphException;
use App\Service\TeamsService;
use Cake\Controller\Component;
use Cake\Http\Response;
use Cake\Http\Session;
use Cake\Log\Log;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Http\Client\ClientExceptionInterface;
use UnexpectedValueException;

/**
 * Session-backed sign-in state for the Entra ID login flow.
 */
class EntraAuthComponent extends Component
{
    private const AUTH_KEY = 'Auth';
    private const FLOW_KEY = 'OAuthFlow';

    public function startLogin(): Response
    {
        $request = (new EntraAuth())->authorizationRequest();
        $this->session()->write(self::FLOW_KEY, ['state' => $request['state'], 'pkce' => $request['pkce']]);

        return $this->getController()->redirect($request['url']);
    }

    /**
     * Completes the OAuth callback; returns false on any failure.
     */
    public function completeLogin(): bool
    {
        $query = $this->getController()->getRequest()->getQueryParams();
        $flow = $this->session()->consume(self::FLOW_KEY);
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';

        if (!is_array($flow) || $code === '' || !hash_equals((string)$flow['state'], $state)) {
            $error = is_string($query['error'] ?? null) ? substr($query['error'], 0, 64) : 'invalid state or code';
            Log::warning('Entra callback rejected: ' . preg_replace('/[^\w .-]/', '?', $error));

            return false;
        }

        try {
            $token = (new EntraAuth())->exchange($code, (string)$flow['pkce']);
            $graph = new GraphClient($token->getToken());
            $profile = (new DirectoryService($graph))->profile();
            $auth = [
                'token' => $token->getToken(),
                'expires' => $token->getExpires(),
                'name' => $profile['name'],
                'email' => $profile['email'],
                'channels' => (new TeamsService($graph))->channels(),
            ];
        } catch (IdentityProviderException | GraphException | ClientExceptionInterface | UnexpectedValueException $e) {
            Log::error('Entra sign-in failed: ' . $e->getMessage());

            return false;
        }

        $this->session()->renew();
        $this->session()->write(self::AUTH_KEY, $auth);

        return true;
    }

    /**
     * Ends the whole session (explicit sign-out).
     */
    public function logout(): void
    {
        $this->session()->destroy();
    }

    /**
     * Drops only the credentials, e.g. after Graph rejects an expired token.
     */
    public function expire(): void
    {
        $this->session()->delete(self::AUTH_KEY);
    }

    /**
     * @return array{name: string, email: string, catalog: ChannelCatalog}|null Null when signed out or the token expired.
     */
    public function user(): ?array
    {
        $auth = $this->session()->read(self::AUTH_KEY);
        if (!is_array($auth) || ($auth['expires'] ?? 0) <= time()) {
            return null;
        }

        return ['name' => $auth['name'], 'email' => (string)($auth['email'] ?? ''), 'catalog' => new ChannelCatalog($auth['channels'])];
    }

    /**
     * Returns a value kept in the session, computing it on first use.
     *
     * @param callable(): array<string, mixed> $load
     * @return array<string, mixed>
     */
    public function remember(string $key, callable $load): array
    {
        $path = 'Cache.' . md5($key);
        $cached = $this->session()->read($path);
        if (is_array($cached)) {
            return $cached;
        }

        $value = $load();
        $this->session()->write($path, $value);

        return $value;
    }

    public function accessToken(): string
    {
        return (string)$this->session()->read(self::AUTH_KEY . '.token');
    }

    private function session(): Session
    {
        return $this->getController()->getRequest()->getSession();
    }
}
