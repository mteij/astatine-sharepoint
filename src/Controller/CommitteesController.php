<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AccessRequest;
use App\Service\AccessRequestException;
use App\Service\AccessRequestRepository;
use App\Service\Channel;
use App\Service\ChannelSource;
use App\Service\DirectoryService;
use App\Service\DriveService;
use App\Service\EntraAuth;
use App\Service\FolderPath;
use App\Service\GraphClient;
use App\Service\GraphException;
use App\Service\RequestableChannels;
use App\Service\TeamsService;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\Log\Log;
use InvalidArgumentException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use UnexpectedValueException;

class CommitteesController extends AppController
{
    /**
     * Actions reachable without signing in; everything else requires a session.
     */
    private const PUBLIC_ACTIONS = ['index', 'login', 'callback', 'logout'];

    private const MAX_ID_LENGTH = 200;
    private const MAX_NOTE_LENGTH = 500;
    public const REQUESTABLE_CACHE_KEY = 'requestable-channels';

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $action = $this->request->getParam('action');
        if (!in_array($action, self::PUBLIC_ACTIONS, true) && $this->EntraAuth->user() === null) {
            $event->setResult($this->redirect('/login'));
        }
    }

    public function index(): void
    {
        $user = $this->EntraAuth->user();
        if ($user === null) {
            $this->viewBuilder()->setTemplate('landing');

            return;
        }

        // Shown at once from the session; the page then asks refresh() for the current list.
        $this->setCommittees($user, $user['loaded'] ? __('You are not in any committee yet.') : __('Loading your committees…'));
        $this->set('requestAccessUrl', $this->requestsEnabled() ? '/request-access' : Configure::read('Portal.requestAccessUrl'));
    }

    /**
     * The committee list as an HTML fragment. Membership changes in Teams show up on the next
     * page load; the Graph calls happen here so the overview itself does not wait for them.
     */
    public function refresh(ChannelSource $channels): ?Response
    {
        $this->request->allowMethod('get');
        if (!$this->EntraAuth->refreshChannels($channels)) {
            return $this->response->withStatus(401);
        }
        $this->EntraAuth->forget(self::REQUESTABLE_CACHE_KEY);

        $user = $this->EntraAuth->user();
        if ($user === null) {
            return $this->response->withStatus(401);
        }
        $this->setCommittees($user, $user['loaded'] ? __('You are not in any committee yet.') : __('Your committees could not be loaded. Reload the page to try again.'));
        $this->viewBuilder()->setLayout('ajax');

        return null;
    }

    /**
     * @param array{catalog: \App\Service\ChannelCatalog} $user
     */
    private function setCommittees(array $user, string $emptyMessage): void
    {
        $this->set('channels', $user['catalog']->all());
        $this->set('showTeam', $user['catalog']->spansMultipleTeams());
        $this->set('emptyMessage', $emptyMessage);
    }

    public function requestAccess(AccessRequestRepository $requests, ChannelSource $channels, RequestableChannels $requestable): ?Response
    {
        if (!$this->requestsEnabled()) {
            throw new NotFoundException();
        }
        $this->request->allowMethod(['get', 'post']);

        // Straight after sign-in the channel list is not loaded yet; the form needs it to leave out joined channels.
        if (!$this->EntraAuth->user()['loaded'] && !$this->EntraAuth->refreshChannels($channels)) {
            return $this->redirect('/login');
        }

        $options = $this->requestableChannels($requestable);
        $selected = '';
        $note = '';
        if ($this->request->is('post')) {
            $selected = $this->cleanText($this->request->getData('committee'), self::MAX_ID_LENGTH);
            $note = $this->cleanText($this->request->getData('note'), self::MAX_NOTE_LENGTH);
            $channel = $options[$selected] ?? null;

            if ($channel === null) {
                $this->Flash->error(__('Please select a committee from the list.'));
            } elseif ($this->deliver($requests, $channel, $note)) {
                $this->Flash->success(__('Request sent to the board.'));

                return $this->redirect('/');
            }
        }

        $this->set('committees', array_map(static fn (array $c): string => $c['name'], $options));
        $this->set(compact('selected', 'note'));
        $this->set('maxNote', self::MAX_NOTE_LENGTH);

        return null;
    }

    /**
     * Channels the user may request: allow-listed and not joined yet, keyed by channel id.
     * The listing is kept in the session; only allow-listed channels are stored there,
     * so other channel names never reach it.
     *
     * @return array<string, array{teamId: string, channelId: string, name: string}>
     */
    private function requestableChannels(RequestableChannels $requestable): array
    {
        $token = $this->EntraAuth->accessToken();
        $all = $this->EntraAuth->recall(self::REQUESTABLE_CACHE_KEY);
        if ($all === null) {
            // Listing the channels takes seconds; do not hold the session file (and block other clicks) meanwhile.
            $this->EntraAuth->releaseSession();
            try {
                $all = $requestable->fetch($token, (new EntraAuth())->appAccessToken());
            } catch (GraphException | IdentityProviderException | ClientExceptionInterface | UnexpectedValueException | RuntimeException $e) {
                Log::error('Could not list requestable committees: ' . $e->getMessage());
                $this->Flash->error(__('The list of committees could not be loaded. Please try again later.'));

                return [];
            }
            $this->EntraAuth->store(self::REQUESTABLE_CACHE_KEY, $all);
        }

        $mine = array_map(static fn (Channel $c): string => $c->channelId, $this->EntraAuth->user()['catalog']->all());

        return $requestable->notJoined($all, $mine);
    }

    private function requestsEnabled(): bool
    {
        return (string)Configure::read('Portal.requestAccessSite') !== '';
    }

    /**
     * Trims, strips control characters and caps the length of free text input.
     */
    private function cleanText(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace('/[^\P{C}\n]/u', '', trim($value)) ?? '';

        return mb_substr($value, 0, $max);
    }

    /**
     * Stores the request unless the person already has an open one for the committee.
     *
     * @param array{teamId: string, channelId: string, name: string} $channel
     */
    private function deliver(AccessRequestRepository $requests, array $channel, string $note): bool
    {
        $user = $this->EntraAuth->user();
        if ($user['email'] === '' || $user['userId'] === '') {
            $this->Flash->error(__('Please sign out and sign in again, then retry.'));

            return false;
        }

        try {
            if ($requests->hasOpenRequest($user['email'], $channel['name'])) {
                $this->Flash->error(__('You already have a request for this committee. The board will get back to you.'));

                return false;
            }
            $requests->add(new AccessRequest(
                $user['name'],
                $user['email'],
                $user['userId'],
                $channel['name'],
                $channel['teamId'],
                $channel['channelId'],
                $note,
            ));
        } catch (AccessRequestException $e) {
            Log::error('Access request not delivered: ' . $e->getMessage());
            $this->Flash->error(__('Your request could not be sent. Please email the board instead.'));

            return false;
        }

        return true;
    }

    public function login(): Response
    {
        return $this->EntraAuth->startLogin();
    }

    public function callback(DirectoryService $directory): Response
    {
        if (!$this->EntraAuth->completeLogin($directory)) {
            $this->Flash->error(__('Sign-in failed. Please try again.'));
        }

        return $this->redirect('/');
    }

    public function logout(): Response
    {
        $this->request->allowMethod('post');
        $this->EntraAuth->logout();

        return $this->redirect('/');
    }

    public function browse(string $slug = '', string ...$segments): ?Response
    {
        $channel = $this->EntraAuth->user()['catalog']->find($slug);
        if ($channel === null) {
            throw new NotFoundException();
        }

        try {
            $path = FolderPath::fromSegments($segments);
        } catch (InvalidArgumentException) {
            throw new NotFoundException();
        }

        // Read the session first, then release it: the Graph calls take a while and must not block other clicks.
        $graph = new GraphClient($this->EntraAuth->accessToken());
        $cacheKey = "files:{$channel->teamId}:{$channel->channelId}";
        $folder = $this->EntraAuth->recall($cacheKey);
        $this->EntraAuth->releaseSession();

        $drive = new DriveService($graph);
        $items = [];
        $folderUrl = null;

        try {
            // The channel's folder never changes, so it is looked up once per session.
            $fresh = $folder === null;
            $folder ??= (new TeamsService($graph))->filesFolder($channel->teamId, $channel->channelId);
            $items = $drive->list($folder['driveId'], $folder['itemId'], $path);
            $folderUrl = $folder['webUrl'] !== '' ? $path->urlUnder($folder['webUrl']) : null;
            if ($fresh) {
                $this->EntraAuth->store($cacheKey, $folder);
            }
        } catch (GraphException $e) {
            if ($e->isUnauthorized()) {
                $this->EntraAuth->logout();

                return $this->redirect('/login');
            }
            if ($e->isNotFound()) {
                throw new NotFoundException();
            }
            if ($e->isForbidden()) {
                $this->EntraAuth->markNoAccess($channel->channelId);
                $this->Flash->error(__('You no longer have access to {0}.', $channel->name));

                return $this->redirect('/');
            }
            Log::error("Graph error browsing {$slug}: " . $e->getMessage());
            $this->set('error', __('Files could not be loaded right now. Please try again later.'));
        }

        $this->set(compact('channel', 'path', 'items', 'folderUrl'));

        return null;
    }
}
