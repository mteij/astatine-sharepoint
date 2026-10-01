<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\CommitteesController;
use App\Service\AccessRequest;
use App\Service\AccessRequestException;
use App\Service\AccessRequestRepository;
use App\Service\ChannelSource;
use App\Service\GraphException;
use Cake\Core\Configure;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class CommitteesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var list<string> Channels flagged private by signIn().
     */
    private array $privateNames = [];

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Entra', [
            'clientId' => 'client',
            'clientSecret' => 'secret',
            'tenantId' => 'tenant',
            'redirectUri' => 'https://example.test/callback',
        ]);
        Configure::write('Portal.requestAccessSite', null);
        Configure::write('Portal.requestAccessTeamIds', []);
        Configure::write('Portal.requestAccessChannelIds', []);
        $this->enableCsrfToken();
    }

    /**
     * Turns the request form on and swaps the SharePoint writer for a mock.
     */
    private function enableRequests(?Exception $failure = null): AccessRequestRepository&MockObject
    {
        Configure::write('Portal.requestAccessSite', 'https://example.sharepoint.com/sites/Board');
        Configure::write('Portal.requestAccessTeamIds', ['t1']);
        Configure::write('Portal.requestAccessChannelIds', ['c-KasCo', 'c-ATAC']);

        $requests = $this->createMock(AccessRequestRepository::class);
        if ($failure !== null) {
            $requests->method('add')->willThrowException($failure);
        }
        $this->mockService(AccessRequestRepository::class, static fn (): AccessRequestRepository => $requests);

        return $requests;
    }

    /**
     * @param list<string> $channelNames Channels the signed-in user can see.
     */
    private function signIn(array $channelNames = ['KasCo'], int $expiresIn = 600, array $extra = [], ?array $refreshedNames = null, ?Exception $refreshFailure = null): void
    {
        $private = $this->privateNames;
        $toChannels = static fn(array $names): array => array_map(
            static fn(string $name): array => ['teamId' => 't1', 'teamName' => 'Astatine', 'channelId' => "c-{$name}", 'name' => $name, 'private' => in_array($name, $private, true)],
            $names,
        );
        $channels = $toChannels($channelNames);

        // The dashboard reloads channels from Graph; by default nothing has changed since sign-in.
        $source = $this->createMock(ChannelSource::class);
        if ($refreshFailure !== null) {
            $source->method('forToken')->willThrowException($refreshFailure);
        } else {
            $source->method('forToken')->willReturn($toChannels($refreshedNames ?? $channelNames));
        }
        $this->mockService(ChannelSource::class, static fn (): ChannelSource => $source);
        $requestable = [
            ['teamId' => 't1', 'teamName' => '', 'channelId' => 'c-KasCo', 'name' => 'KasCo'],
            ['teamId' => 't1', 'teamName' => '', 'channelId' => 'c-ATAC', 'name' => 'ATAC'],
        ];
        $this->session([
            'Auth' => ['token' => 't', 'expires' => time() + $expiresIn, 'name' => 'Sam', 'email' => 'sam@utwente.nl', 'userId' => 'u-sam', 'channels' => $channels],
            'Cache' => [md5(CommitteesController::REQUESTABLE_CACHE_KEY) => $requestable],
            ...$extra,
        ]);
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

    public function testRequestAccessLinksToMailtoWithoutSite(): void
    {
        $this->signIn();
        $this->get('/');

        $this->assertResponseContains('href="mailto:');
    }

    public function testRequestAccessLinksToFormWithSite(): void
    {
        $this->enableRequests();
        $this->signIn();
        $this->get('/');

        $this->assertResponseContains('href="/request-access"');
    }

    public function testRequestAccessFormIs404WithoutSite(): void
    {
        $this->signIn();
        $this->get('/request-access');

        $this->assertResponseCode(404);
    }

    public function testRequestAccessRequiresLogin(): void
    {
        $this->enableRequests();
        $this->get('/request-access');

        $this->assertRedirect('/login');
    }

    public function testRequestAccessPostsSessionIdentityToBoard(): void
    {
        $requests = $this->enableRequests();
        $requests->expects($this->once())->method('add')->with($this->callback(
            static fn (AccessRequest $r): bool => [$r->name, $r->email, $r->userId, $r->committee, $r->teamId, $r->channelId, $r->note]
                === ['Sam', 'sam@utwente.nl', 'u-sam', 'ATAC', 't1', 'c-ATAC', 'Hi'],
        ));
        $this->signIn();
        $this->post('/request-access', ['committee' => 'c-ATAC', 'note' => 'Hi', 'name' => 'Mallory']);

        $this->assertRedirect('/');
        $this->assertFlashMessage('Request sent to the board.');
    }

    public function testFormListsOnlyChannelsTheUserIsNotIn(): void
    {
        $this->enableRequests();
        $this->signIn();
        $this->get('/request-access');

        $this->assertResponseOk();
        $this->assertResponseContains('<option value="c-ATAC">ATAC</option>');
        $this->assertResponseNotContains('<option value="c-KasCo">');
    }

    public function testChannelsOffAllowListAreNeitherListedNorAccepted(): void
    {
        $this->enableRequests()->expects($this->never())->method('add');
        Configure::write('Portal.requestAccessChannelIds', ['c-Other']);
        $this->signIn();

        $this->get('/request-access');
        $this->assertResponseNotContains('<option value="c-ATAC">');

        $this->post('/request-access', ['committee' => 'c-ATAC']);
        $this->assertResponseContains('Please select a committee');
    }

    public function testEmptyAllowListOffersNothing(): void
    {
        $this->enableRequests()->expects($this->never())->method('add');
        Configure::write('Portal.requestAccessChannelIds', []);
        $this->signIn();

        $this->get('/request-access');
        $this->assertResponseNotContains('<option value="c-');
    }

    public function testRequestAccessRejectsChannelNotInList(): void
    {
        $this->enableRequests()->expects($this->never())->method('add');
        $this->signIn();
        $this->post('/request-access', ['committee' => 'c-KasCo']);

        $this->assertResponseOk();
        $this->assertResponseContains('Please select a committee');
    }

    public function testRequestAccessRejectsFreeText(): void
    {
        $this->enableRequests()->expects($this->never())->method('add');
        $this->signIn();
        $this->post('/request-access', ['committee' => 'Whatever', 'note' => '']);

        $this->assertResponseOk();
        $this->assertResponseContains('Please select a committee');
    }

    public function testRequestAccessIsBlockedWhileARequestIsOpen(): void
    {
        $requests = $this->enableRequests();
        $requests->expects($this->once())->method('hasOpenRequest')->with('sam@utwente.nl', 'ATAC')->willReturn(true);
        $requests->expects($this->never())->method('add');
        $this->signIn();
        $this->post('/request-access', ['committee' => 'c-ATAC']);

        $this->assertResponseOk();
        $this->assertResponseContains('already have a request');
    }

    public function testRequestAccessNeedsEmailFromSession(): void
    {
        $this->enableRequests()->expects($this->never())->method('add');
        $this->signIn(extra: ['Auth' => ['token' => 't', 'expires' => time() + 600, 'name' => 'Sam', 'channels' => []]]);
        $this->post('/request-access', ['committee' => 'c-ATAC']);

        $this->assertResponseOk();
        $this->assertResponseContains('sign out and sign in again');
    }

    public function testRequestAccessShowsErrorWhenLookupFails(): void
    {
        $requests = $this->enableRequests();
        $requests->method('hasOpenRequest')->willThrowException(new AccessRequestException('boom'));
        $requests->expects($this->never())->method('add');
        $this->signIn();
        $this->post('/request-access', ['committee' => 'c-ATAC']);

        $this->assertResponseOk();
        $this->assertResponseContains('could not be sent');
    }

    public function testRequestAccessShowsErrorWhenStoringFails(): void
    {
        $this->enableRequests(new AccessRequestException('boom'));
        $this->signIn();
        $this->post('/request-access', ['committee' => 'c-ATAC']);

        $this->assertResponseOk();
        $this->assertResponseContains('could not be sent');
    }

    public function testLoginRedirectsToEntraWithPkceAndState(): void
    {
        $this->get('/login');

        $this->assertRedirectContains('https://login.microsoftonline.com/tenant/oauth2/v2.0/authorize');
        $this->assertRedirectContains('code_challenge_method=S256');
        $this->assertRedirectContains('prompt=select_account');
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

    public function testDashboardRefreshShowsNewlyJoinedCommittee(): void
    {
        $this->signIn(['KasCo'], refreshedNames: ['KasCo', 'ATAC']);
        $this->get('/committees');

        $this->assertResponseContains('ATAC');
    }

    public function testDashboardRefreshDropsLeftCommittee(): void
    {
        $this->signIn(['KasCo', 'ATAC'], refreshedNames: ['KasCo']);
        $this->get('/committees');

        $this->assertResponseNotContains('ATAC');
    }

    public function testDashboardHidesPrivateChannelsKnownToBeRefused(): void
    {
        $this->privateNames = ['KasCo'];
        $this->signIn(['KasCo', 'ATAC'], 600, ['Auth.access' => ['c-KasCo' => ['ok' => false, 'at' => time()]]]);
        $this->get('/committees');

        $this->assertResponseContains('ATAC');
        $this->assertResponseNotContains('KasCo');
    }

    public function testDashboardKeepsPrivateChannelsKnownToBeOpen(): void
    {
        $this->privateNames = ['KasCo'];
        $this->signIn(['KasCo'], 600, ['Auth.access' => ['c-KasCo' => ['ok' => true, 'at' => time()]]]);
        $this->get('/committees');

        $this->assertResponseContains('KasCo');
    }

    public function testDashboardKeepsKnownCommitteesWhenRefreshFails(): void
    {
        $this->signIn(['KasCo'], refreshFailure: new GraphException('boom', 503));
        $this->get('/committees');

        $this->assertResponseContains('KasCo');
    }

    public function testRefreshSignsOutWhenGraphRejectsToken(): void
    {
        $this->signIn(['KasCo'], refreshFailure: new GraphException('expired', 401));
        $this->get('/committees');

        $this->assertResponseCode(401);
        $this->assertSession(null, 'Auth');
    }

    public function testOverviewDoesNotWaitForGraph(): void
    {
        // A failing refresh would sign the user out; the overview must not call it at all.
        $this->signIn(['KasCo'], refreshFailure: new GraphException('expired', 401));
        $this->get('/');

        $this->assertResponseContains('KasCo');
        $this->assertResponseContains('id="committees"');
        $this->assertSession('Sam', 'Auth.name');
    }

    public function testOverviewShowsLoadingBeforeTheFirstRefresh(): void
    {
        $this->signIn([]);
        $this->get('/');

        $this->assertResponseContains('Loading your committees');
    }

    public function testRefreshReturnsOnlyTheList(): void
    {
        $this->signIn(['KasCo']);
        $this->get('/committees');

        $this->assertResponseOk();
        $this->assertResponseContains('KasCo');
        $this->assertResponseNotContains('<html');
        $this->assertSession(true, 'Auth.loaded');
    }

    public function testRefreshRequiresLogin(): void
    {
        $this->get('/committees');

        $this->assertRedirect('/login');
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
