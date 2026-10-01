<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Reads the signed-in user's profile via Graph.
 */
final class DirectoryService
{
    /**
     * @return array{id: string, name: string, email: string}
     */
    public function profile(string $accessToken): array
    {
        $me = (new GraphClient($accessToken))->get('/me', ['$select' => 'id,displayName,mail,userPrincipalName']);

        return [
            'id' => (string)($me['id'] ?? ''),
            'name' => (string)($me['displayName'] ?? ''),
            'email' => (string)($me['mail'] ?? $me['userPrincipalName'] ?? ''),
        ];
    }
}
