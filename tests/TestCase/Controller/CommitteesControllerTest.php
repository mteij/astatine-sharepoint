<?php

declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class CommitteesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Entra', [
            'clientId' => 'client',
            'clientSecret' => 'secret',
            'tenantId' => 'tenant',
            'redirectUri' => 'https://example.test/callback',
        ]);
        $this->enableCsrfToken();
    }

    /**
     * @param list<string> $channelNames Channels the signed-in user can see.
     */
    private function signIn(array $channelNames = ['KasCo'], int $expiresIn = 600): void
    {
        $channels = array_map(
            static fn (string $name): array => ['teamId' => 't1', 'teamName' => 'Astatine', 'channelId' => "c-{$name}", 'name' => $name],
            $channelNames,
        );
        $this->session(['Auth' => ['token' => 't', 'expires' => time() + $expiresIn, 'name' => 'Sam', 'channels' => $channels]]);
    }

    public function testAnonymousSeesLanding(): void
    {
        $this->get('/');

        $this->assertResponseOk();
        $this->assertResponseContains('Sign in with Microsoft');
        $this->assertResponseNotContains('KasCo');
        $this->assertResponseNotContains('Request access');
    }

    public function testSignedInUserSeesRequestAccessLink(): void
    {
        $this->signIn();
        $this->get('/');

        $this->assertResponseContains('Request access');
    }

    public function testLoginRedirectsToEntraWithPkceAndState(): void
    {
        $this->get('/login');

        $this->assertRedirectContains('https://login.microsoftonline.com/tenant/oauth2/v2.0/authorize');
        $this->assertRedirectContains('code_challenge_method=S256');
        $this->assertRedirectContains('state=');
    }

    public function testCallbackWithWrongStateFails(): void
    {
        $this->session(['OAuthFlow' => ['state' => 'good', 'pkce' => 'v']]);
        $this->get('/callback?code=abc&state=evil');

        $this->assertRedirect('/');
        $this->assertSession(null, 'Auth');
    }

    public function testCallbackIgnoresArrayValuedParams(): void
    {
        $this->session(['OAuthFlow' => ['state' => 'good', 'pkce' => 'v']]);
        $this->get('/callback?code[]=x&state[]=good&error[]=y');

        $this->assertRedirect('/');
        $this->assertSession(null, 'Auth');
    }

    public function testDashboardOnlyListsMemberCommittees(): void
    {
        $this->signIn();
        $this->get('/');

        $this->assertResponseContains('KasCo');
        $this->assertResponseNotContains('ATAC');
    }

    public function testExpiredSessionIsTreatedAsAnonymous(): void
    {
        $this->signIn(['KasCo'], -1);
        $this->get('/');

        $this->assertResponseContains('Sign in with Microsoft');
    }

    public function testBrowseRequiresLogin(): void
    {
        $this->get('/c/kasco');

        $this->assertRedirect('/login');
    }

    public function testBrowseUnknownChannelIsNotFound(): void
    {
        $this->signIn();
        $this->get('/c/atac');

        $this->assertResponseCode(404);
    }

    /**
     * @dataProvider traversalUrls
     */
    public function testBrowseRejectsUnsafePaths(string $url): void
    {
        $this->signIn();
        $this->get($url);

        $this->assertResponseCode(404);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function traversalUrls(): array
    {
        return [
            'encoded slash' => ['/c/kasco/..%2Fsecret'],
            'encoded dots' => ['/c/kasco/%2e%2e/secret'],
            'colon segment' => ['/c/kasco/a%3Ab'],
        ];
    }

    public function testLogoutRequiresPost(): void
    {
        $this->get('/logout');

        $this->assertResponseCode(405);
    }

    public function testLogoutClearsSession(): void
    {
        $this->signIn();
        $this->post('/logout');

        $this->assertRedirect('/');
        $this->assertSession(null, 'Auth');
    }
}
