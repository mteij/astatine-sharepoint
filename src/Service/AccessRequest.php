<?php
declare(strict_types=1);

namespace App\Service;

/**
 * One person asking to join one committee channel.
 */
final class AccessRequest
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $userId,
        public readonly string $committee,
        public readonly string $teamId,
        public readonly string $channelId,
        public readonly string $note,
    ) {
    }
}
