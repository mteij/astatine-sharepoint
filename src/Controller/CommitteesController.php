<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AccessRequest;
use App\Service\AccessRequestException;
use App\Service\AccessRequestRepository;
use App\Service\Channel;
use App\Service\ChannelSource;
use App\Service\DriveService;
use App\Service\EntraAuth;
use App\Service\FolderPath;
use App\Service\GraphClient;
use App\Service\GraphException;
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

    public function index(ChannelSource $channels): void
    {
        $user = $this->EntraAuth->user();
        if ($user !== null) {
            // Membership changes in Teams show up on the next page load, not only after signing in again.
            $user = $this->EntraAuth->refreshChannels($channels) ? $this->EntraAuth->user() : null;
            $this->EntraAuth->forget(self::REQUESTABLE_CACHE_KEY);
        }
        if ($user === null) {
            $this->viewBuilder()->setTemplate('landing');

            return;
        }

        $this->set('channels', $user['catalog']->all());
        $this->set('showTeam', $user['catalog']->spansMultipleTeams());
        $this->set('requestAccessUrl', $this->requestsEnabled() ? '/request-access' : Configure::read('Portal.requestAccessUrl'));
    }

    public function requestAccess(AccessRequestRepository $requests): ?Response
    {
        if (!$this->requestsEnabled()) {
            throw new NotFoundException();
        }
        $this->request->allowMethod(['get', 'post']);

        $options = $this->requestableChannels();
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
     * Teams whose channels can be requested: the configured ones, otherwise
     * every team the signed-in user belongs to.
     *
     * @return list<string>
     */
    private function requestableTeamIds(): array
    {
        $configured = (array)Configure::read('Portal.requestAccessTeamIds');
        if ($configured !== []) {
            return array_values($configured);
        }

        return (new TeamsService(new GraphClient($this->EntraAuth->accessToken())))->joinedTeamIds();
    }

    /**
     * Channels that exist in the requestable teams but that the user is not in yet,
     * keyed by channel id. Listed with an app-only token and kept in the session.
     *
     * @return array<string, array{teamId: string, channelId: string, name: string}>
     */
    private function requestableChannels(): array
    {
        try {
            $all = $this->EntraAuth->remember(self::REQUESTABLE_CACHE_KEY, function (): array {
                $teamIds = $this->requestableTeamIds();

                if ($teamIds === []) {
                    return [];
                }
                $channels = (new TeamsService(new GraphClient((new EntraAuth())->appAccessToken())))->channelsOfTeams($teamIds);

                // Only allow-listed channels are stored, so other channel names never reach the session.
                return $this->allowListed($channels);
            });
        } catch (GraphException | IdentityProviderException | ClientExceptionInterface | UnexpectedValueException | RuntimeException $e) {
            Log::error('Could not list requestable committees: ' . $e->getMessage());
            $this->Flash->error(__('The list of committees could not be loaded. Please try again later.'));

            return [];
        }

        $mine = array_map(static fn (Channel $c): string => $c->channelId, $this->EntraAuth->user()['catalog']->all());
        $options = [];
        foreach ($this->allowListed($all) as $channel) {
            if (!in_array($channel['channelId'], $mine, true)) {
                $options[$channel['channelId']] = $channel;
            }
        }

        return $options;
    }

    /**
     * Keeps only channels whose id is on the configured allow-list. An empty list
     * allows nothing, so a new or renamed confidential channel is never offered.
     *
     * @param array<int, array{teamId: string, teamName: string, channelId: string, name: string}> $channels
     * @return list<array{teamId: string, teamName: string, channelId: string, name: string}>
     */
    private function allowListed(array $channels): array
    {
        $allowed = (array)Configure::read('Portal.requestAccessChannelIds');

        return array_values(array_filter(
            $channels,
            static fn (array $c): bool => in_array($c['channelId'], $allowed, true),
        ));
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

    public function callback(): Response
    {
        if (!$this->EntraAuth->completeLogin()) {
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

        $graph = new GraphClient($this->EntraAuth->accessToken());
        $drive = new DriveService($graph);
        $items = [];
        $folderUrl = null;

        try {
            // The channel's folder never changes, so it is looked up once per session.
            $folder = $this->EntraAuth->remember(
                "files:{$channel->teamId}:{$channel->channelId}",
                fn(): array => (new TeamsService($graph))->filesFolder($channel->teamId, $channel->channelId),
            );
            $items = $drive->list($folder['driveId'], $folder['itemId'], $path);
            $folderUrl = $folder['webUrl'] !== '' ? $path->urlUnder($folder['webUrl']) : null;
        } catch (GraphException $e) {
            if ($e->isUnauthorized()) {
                $this->EntraAuth->expire();

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
