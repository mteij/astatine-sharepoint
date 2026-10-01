<?php
declare(strict_types=1);

namespace App\Service;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Stores each request as an item in a SharePoint list on the board site. A flow
 * on that list ("When an item is created") posts it to the board channel, where
 * the board approves or denies it and the flow sets the item's Status.
 *
 * Expected list columns (internal names): Title (holds the email, displayed as
 * "Email"), Name, Committee, Note, UserId, TeamId, ChannelId (all single-line text)
 * and Status (choice: Pending, Approved, Denied, Expired).
 */
final class SharePointAccessRequests implements AccessRequestRepository
{
    private const STATUS_PENDING = 'Pending';
    private const STATUS_DENIED = 'Denied';
    private const DENIED_RETRY_DAYS = 7;
    // The approval flow cannot wait longer than 30 days; a Pending item past that will never be answered.
    private const PENDING_MAX_AGE_DAYS = 28;
    private const LOOKUP_LIMIT = 100;

    /**
     * @param callable(): GraphClient $graph Built lazily so pages that never use it do not fetch an app token.
     * @param string $siteUrl Full site URL, e.g. https://tenant.sharepoint.com/sites/Board
     */
    public function __construct(
        private readonly mixed $graph,
        private readonly string $siteUrl,
        private readonly string $listName,
    ) {
    }

    public function hasOpenRequest(string $email, string $committee): bool
    {
        $path = $this->itemsPath();
        $filter = sprintf(
            "fields/Title eq '%s' and fields/Committee eq '%s'",
            $this->quote($email),
            $this->quote($committee),
        );

        $items = $this->guarded(fn (): array => ($this->graph)()->get($path, [
            '$filter' => $filter,
            '$expand' => 'fields($select=Status)',
            '$select' => 'id,createdDateTime,lastModifiedDateTime',
            '$top' => self::LOOKUP_LIMIT,
        ], ['Prefer' => 'HonorNonIndexedQueriesWarningMayFailRandomly'])['value'] ?? []);

        foreach ($items as $item) {
            if ($this->blocks($item)) {
                return true;
            }
        }

        return false;
    }

    public function add(AccessRequest $request): void
    {
        $path = $this->itemsPath();
        $this->guarded(fn (): array => ($this->graph)()->post($path, ['fields' => [
            'Title' => $request->email,
            'Name' => $request->name,
            'Committee' => $request->committee,
            'Note' => $request->note,
            'UserId' => $request->userId,
            'TeamId' => $request->teamId,
            'ChannelId' => $request->channelId,
            'Status' => self::STATUS_PENDING,
        ]]));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function blocks(array $item): bool
    {
        $status = (string)($item['fields']['Status'] ?? self::STATUS_PENDING);
        if ($status === self::STATUS_PENDING) {
            return !$this->isOlderThan($item['createdDateTime'] ?? '', self::PENDING_MAX_AGE_DAYS);
        }
        if ($status !== self::STATUS_DENIED) {
            return false;
        }

        return !$this->isOlderThan($item['lastModifiedDateTime'] ?? '', self::DENIED_RETRY_DAYS);
    }

    /**
     * An unreadable timestamp counts as recent, so a malformed item errs on the side of blocking.
     */
    private function isOlderThan(string $timestamp, int $days): bool
    {
        $time = strtotime($timestamp);

        return $time !== false && $time < strtotime("-{$days} days");
    }

    /**
     * Runs a Graph call, turning transport and auth failures into AccessRequestException.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function guarded(callable $call): mixed
    {
        try {
            return $call();
        } catch (GraphException | IdentityProviderException | ClientExceptionInterface | UnexpectedValueException | RuntimeException $e) {
            throw new AccessRequestException(
                sprintf('Request list call failed (%s %d): %s', $e::class, $e->getCode(), $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Escapes a value for use inside a single-quoted OData string literal.
     */
    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function itemsPath(): string
    {
        $url = parse_url($this->siteUrl);
        $host = $url['host'] ?? '';
        $sitePath = trim($url['path'] ?? '', '/');
        if (($url['scheme'] ?? '') !== 'https' || !str_ends_with($host, '.sharepoint.com') || $sitePath === '') {
            throw new AccessRequestException('The access request site must be an https SharePoint site URL.');
        }

        $segments = implode('/', array_map('rawurlencode', explode('/', $sitePath)));

        return "/sites/{$host}:/{$segments}:/lists/" . rawurlencode($this->listName) . '/items';
    }
}
