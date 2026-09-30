<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AccessRequestException;
use App\Service\AccessRequestNotifier;
use App\Service\Channel;
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

    private const REQUEST_COOLDOWN_SECONDS = 3600;
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

        $this->set('channels', $user['catalog']->all());
        $this->set('showTeam', $user['catalog']->spansMultipleTeams());
        $this->set('requestAccessUrl', $this->webhookUrl() !== '' ? '/request-access' : Configure::read('Portal.requestAccessUrl'));
    }

    public function requestAccess(): ?Response
    {
        $webhookUrl = $this->webhookUrl();
        if ($webhookUrl === '') {
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
            } elseif ($this->recentlyRequested($selected)) {
                $this->Flash->error(__('You already requested access to this committee. Please wait a while before trying again.'));
            } elseif ($this->deliver($webhookUrl, $selected, $channel, $note)) {
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
     * Channels that exist in the configured teams but that the user is not in yet,
     * keyed by channel id. Listed with an app-only token and kept in the session.
     *
     * @return array<string, array{channelId: string, name: string}>
     */
    private function requestableChannels(): array
    {
        $teamIds = (array)Configure::read('Portal.requestAccessTeamIds');
        if ($teamIds === []) {
            return [];
        }

        try {
            $all = $this->EntraAuth->remember(
                self::REQUESTABLE_CACHE_KEY,
                static fn (): array => (new TeamsService(new GraphClient((new EntraAuth())->appAccessToken())))
                    ->channelsOfTeams($teamIds),
            );
        } catch (GraphException | IdentityProviderException | ClientExceptionInterface | UnexpectedValueException | RuntimeException $e) {
            Log::error('Could not list requestable committees: ' . $e->getMessage());
            $this->Flash->error(__('The list of committees could not be loaded. Please try again later.'));

            return [];
        }

        $mine = array_map(static fn (Channel $c): string => $c->channelId, $this->EntraAuth->user()['catalog']->all());
        $options = [];
        foreach ($all as $channel) {
            if (!in_array($channel['channelId'], $mine, true)) {
                $options[$channel['channelId']] = $channel;
            }
        }

        return $options;
    }

    private function webhookUrl(): string
    {
        return (string)Configure::read('Portal.requestAccessWebhookUrl');
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

    private function recentlyRequested(string $channelId): bool
    {
        $last = $this->request->getSession()->read($this->cooldownKey($channelId));

        return is_int($last) && time() - $last < self::REQUEST_COOLDOWN_SECONDS;
    }

    private function cooldownKey(string $channelId): string
    {
        return 'AccessRequests.' . md5($channelId);
    }

    /**
     * @param array{channelId: string, name: string} $channel
     */
    private function deliver(string $webhookUrl, string $channelId, array $channel, string $note): bool
    {
        $user = $this->EntraAuth->user();
        try {
            (new AccessRequestNotifier($webhookUrl))->send($user['name'], $user['email'], $channel['name'], $note);
        } catch (AccessRequestException $e) {
            Log::error('Access request not delivered: ' . $e->getMessage());
            $this->Flash->error(__('Your request could not be sent. Please email the board instead.'));

            return false;
        }
        $this->request->getSession()->write($this->cooldownKey($channelId), time());

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
            Log::error("Graph error browsing {$slug}: " . $e->getMessage());
            $this->set('error', __('Files could not be loaded right now. Please try again later.'));
        }

        $this->set(compact('channel', 'path', 'items', 'folderUrl'));

        return null;
    }
}
