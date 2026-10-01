<?php
declare(strict_types=1);

namespace App\Controller\Component;

use App\Service\ChannelAccessFilter;
use App\Service\ChannelCatalog;
use App\Service\ChannelSource;
use App\Service\DirectoryService;
use App\Service\EntraAuth;
use App\Service\GraphException;
use App\Service\ParallelFolderProbe;
use App\Service\UserChannelCache;
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
    private const ACCESS_KEY = 'Auth.access';

    public function startLogin(): Response
    {
        $request = (new EntraAuth())->authorizationRequest();
        $this->session()->write(self::FLOW_KEY, ['state' => $request['state'], 'pkce' => $request['pkce']]);

        return $this->getController()->redirect($request['url']);
    }

    /**
     * Completes the OAuth callback; returns false on any failure.
     */
    public function completeLogin(DirectoryService $directory): bool
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
            $profile = $directory->profile($token->getToken());
            // A returning user sees the last known list at once; refresh() replaces it in the background.
            $remembered = (new UserChannelCache())->load($profile['id']);
            $auth = [
                'token' => $token->getToken(),
                'expires' => $token->getExpires(),
                'name' => $profile['name'],
                'email' => $profile['email'],
                'userId' => $profile['id'],
                'channels' => $remembered['channels'] ?? [],
                'access' => $remembered['access'] ?? [],
                'loaded' => $remembered !== null,
            ];
        } catch (IdentityProviderException | GraphException | ClientExceptionInterface | UnexpectedValueException $e) {
            Log::error('Entra sign-in failed: ' . $e->getMessage());

            return false;
        }

        $this->session()->renew();
        $this->session()->delete('Cache');
        $this->session()->write(self::AUTH_KEY, $auth);

        return true;
    }

    /**
     * Ends the whole session: explicit sign-out, or Graph rejected the token and nothing of it should stay on disk.
     */
    public function logout(): void
    {
        $this->session()->destroy();
    }

    /**
     * @return array{name: string, email: string, userId: string, catalog: \App\Service\ChannelCatalog, loaded: bool}|null Null when signed out or the token expired.
     */
    public function user(): ?array
    {
        $auth = $this->session()->read(self::AUTH_KEY);
        if (!is_array($auth)) {
            return null;
        }
        if (($auth['expires'] ?? 0) <= time()) {
            // Do not leave an expired token, or the previous user's cached lists, in the session file.
            $this->session()->destroy();

            return null;
        }

        return ['name' => $auth['name'], 'email' => (string)($auth['email'] ?? ''), 'userId' => (string)($auth['userId'] ?? ''), 'catalog' => new ChannelCatalog($auth['channels']), 'loaded' => (bool)($auth['loaded'] ?? false)];
    }

    /**
     * Reloads the user's channels so committees joined or left since sign-in show up.
     * A temporary Graph failure keeps the previous list; returns false when the token was rejected.
     */
    public function refreshChannels(ChannelSource $source): bool
    {
        $auth = (array)$this->session()->read(self::AUTH_KEY);
        $token = (string)($auth['token'] ?? '');
        $userId = (string)($auth['userId'] ?? '');
        $known = (array)($auth['access'] ?? []);

        // The Graph calls below take seconds; do not hold the session file (and block every other request) meanwhile.
        $this->releaseSession();

        try {
            [$channels, $access] = $this->accessFilter($token)->filter($source->forToken($token), $known, time());
        } catch (GraphException $e) {
            if ($e->isUnauthorized()) {
                $this->logout();

                return false;
            }
            Log::warning('Could not refresh channels: ' . $e->getMessage());

            return true;
        } catch (ClientExceptionInterface | UnexpectedValueException $e) {
            Log::warning('Could not refresh channels: ' . $e->getMessage());

            return true;
        }

        $this->session()->write([
            self::AUTH_KEY . '.channels' => $channels,
            self::ACCESS_KEY => $access,
            self::AUTH_KEY . '.loaded' => true,
        ]);
        if ($userId !== '') {
            (new UserChannelCache())->save($userId, $channels, $access);
        }

        return true;
    }

    /**
     * Hides a channel Graph refused to open after it was listed (access changed since the last check).
     */
    public function markNoAccess(string $channelId): void
    {
        $known = $this->accessFilter($this->accessToken())
            ->refused((array)$this->session()->read(self::ACCESS_KEY), $channelId, time());
        $this->session()->write(self::ACCESS_KEY, $known);

        $channels = (array)$this->session()->read(self::AUTH_KEY . '.channels');
        $this->session()->write(
            self::AUTH_KEY . '.channels',
            array_values(array_filter($channels, static fn (array $c): bool => $c['channelId'] !== $channelId)),
        );
    }

    private function accessFilter(string $accessToken): ChannelAccessFilter
    {
        return new ChannelAccessFilter(new ParallelFolderProbe($accessToken));
    }

    /**
     * Drops a value stored by remember().
     */
    public function forget(string $key): void
    {
        $this->session()->delete('Cache.' . md5($key));
    }

    /**
     * Returns a value kept in the session, computing it on first use.
     *
     * @param callable(): array<string, mixed> $load
     * @return array<string, mixed>
     */
    public function remember(string $key, callable $load): array
    {
        $cached = $this->recall($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $load();
        $this->store($key, $value);

        return $value;
    }

    /**
     * @return array<string, mixed>|null The value stored under the key, or null.
     */
    public function recall(string $key): ?array
    {
        $cached = $this->session()->read('Cache.' . md5($key));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function store(string $key, array $value): void
    {
        $this->session()->write('Cache.' . md5($key), $value);
    }

    /**
     * Lets other requests of this user run while this one waits on Graph. PHP keeps the session file locked
     * until the request ends, so a long call would otherwise freeze every click in the meantime. Read what
     * you need first: any later session access (reads and writes) reopens the session.
     */
    public function releaseSession(): void
    {
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            $this->session()->close();
        }
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
