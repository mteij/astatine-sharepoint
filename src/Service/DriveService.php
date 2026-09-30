<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads folder contents from a SharePoint document library via Graph.
 * Folders are addressed as a base folder item plus a relative path.
 */
final class DriveService
{
    private const FIELDS = 'name,size,webUrl,lastModifiedDateTime,folder';

    public function __construct(private readonly GraphClient $graph)
    {
    }

    /**
     * Folders first, then files, each sorted by name.
     *
     * @return list<DriveItem>
     */
    public function list(string $driveId, string $baseItemId, FolderPath $path): array
    {
        $raw = $this->graph->getAll(
            $this->itemPath($driveId, $baseItemId, $path) . ($path->isRoot() ? '/children' : ':/children'),
            ['$select' => self::FIELDS, '$top' => 200],
        );

        $items = array_map(DriveItem::fromGraph(...), $raw);
        usort($items, static fn (DriveItem $a, DriveItem $b): int =>
            [!$a->isFolder, strtolower($a->name)] <=> [!$b->isFolder, strtolower($b->name)]);

        return $items;
    }

    private function itemPath(string $driveId, string $baseItemId, FolderPath $path): string
    {
        $base = '/drives/' . rawurlencode($driveId) . '/items/' . rawurlencode($baseItemId);

        return $path->isRoot() ? $base : $base . ':/' . $path->encoded();
    }
}
