<?php
declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

/**
 * Immutable, validated folder path inside a drive.
 *
 * Rejects traversal (`.`/`..`) and characters SharePoint forbids, so a
 * path taken from a URL can safely be embedded in a Graph request.
 */
final class FolderPath
{
    /**
     * @param list<string> $segments
     */
    private function __construct(private readonly array $segments)
    {
    }

    public static function root(): self
    {
        return new self([]);
    }

    public static function parse(string $path): self
    {
        return self::fromSegments(explode('/', trim($path, '/')));
    }

    /**
     * @param array<string> $segments
     */
    public static function fromSegments(array $segments): self
    {
        $clean = [];
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..' || preg_match('/[\x00-\x1f\/\\\\:]/', $segment)) {
                throw new InvalidArgumentException('Invalid path segment.');
            }
            $clean[] = $segment;
        }

        return new self($clean);
    }

    /**
     * @return list<string>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function isRoot(): bool
    {
        return $this->segments === [];
    }

    public function join(self $other): self
    {
        return new self([...$this->segments, ...$other->segments]);
    }

    /**
     * Path with each segment percent-encoded, ready for a Graph URL.
     */
    public function encoded(): string
    {
        return implode('/', array_map('rawurlencode', $this->segments));
    }

    /**
     * Browser URL of this folder, given the URL of the folder it is relative to.
     * SharePoint folder URLs are the parent URL plus the encoded folder name.
     */
    public function urlUnder(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . ($this->isRoot() ? '' : '/' . $this->encoded());
    }

    /**
     * Cumulative paths from the first segment down, for breadcrumbs.
     *
     * @return list<array{name: string, path: self}>
     */
    public function breadcrumbs(): array
    {
        $crumbs = [];
        foreach ($this->segments as $i => $name) {
            $crumbs[] = ['name' => $name, 'path' => new self(array_slice($this->segments, 0, $i + 1))];
        }

        return $crumbs;
    }
}
