<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Where committee access requests are stored for the board to handle.
 */
interface AccessRequestRepository
{
    /**
     * Whether this person already has a request for the committee that the board
     * has not settled, or settled with a denial recently enough to block a retry.
     *
     * @throws \App\Service\AccessRequestException When the lookup failed.
     */
    public function hasOpenRequest(string $email, string $committee): bool;

    /**
     * @throws \App\Service\AccessRequestException When the request could not be stored.
     */
    public function add(AccessRequest $request): void;
}
