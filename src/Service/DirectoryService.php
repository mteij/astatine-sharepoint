<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Reads the signed-in user's profile via Graph.
 */
final class DirectoryService
{
    public function __construct(private readonly GraphClient $graph)
    {
    }

    /**
     * @return array{name: string, email: string}
     */
    public function profile(): array
    {
        $me = $this->graph->get('/me', ['$select' => 'displayName,mail,userPrincipalName']);

        return [
            'name' => (string)($me['displayName'] ?? ''),
            'email' => (string)($me['mail'] ?? $me['userPrincipalName'] ?? ''),
        ];
    }
}
