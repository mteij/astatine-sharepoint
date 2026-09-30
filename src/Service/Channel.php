<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A Teams channel the signed-in user can see; shown as a committee tile.
 */
final class Channel
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $teamName,
        public readonly string $teamId,
        public readonly string $channelId,
    ) {
    }
}
