<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;

final class DriveItem
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isFolder,
        public readonly ?int $size,
        public readonly ?DateTimeImmutable $modified,
        public readonly string $webUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $data A Graph driveItem.
     */
    public static function fromGraph(array $data): self
    {
        $modified = isset($data['lastModifiedDateTime'])
            ? (new DateTimeImmutable($data['lastModifiedDateTime']))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            : null;

        return new self(
            (string)$data['name'],
            isset($data['folder']),
            isset($data['size']) ? (int)$data['size'] : null,
            $modified,
            (string)($data['webUrl'] ?? ''),
        );
    }
}
