<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Http\Client;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Posts an access request as an Adaptive Card to the board's Teams channel
 * through a Workflows ("Post to a channel when a webhook request is received") URL.
 */
final class AccessRequestNotifier
{
    private readonly Client $http;

    public function __construct(private readonly string $webhookUrl, ?Client $http = null)
    {
        if (!str_starts_with($webhookUrl, 'https://')) {
            throw new AccessRequestException('The access request webhook must use https.');
        }
        $this->http = $http ?? new Client(['timeout' => 10]);
    }

    public function send(string $name, string $email, string $committee, string $note): void
    {
        $facts = [
            ['title' => 'Name', 'value' => $name],
            ['title' => 'Email', 'value' => $email !== '' ? $email : 'unknown'],
            ['title' => 'Committee', 'value' => $committee],
        ];
        if ($note !== '') {
            $facts[] = ['title' => 'Note', 'value' => $note];
        }

        try {
            $response = $this->http->post($this->webhookUrl, json_encode($this->message($facts), JSON_THROW_ON_ERROR), [
                'type' => 'json',
            ]);
        } catch (ClientExceptionInterface $e) {
            throw new AccessRequestException('Webhook request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->isOk()) {
            throw new AccessRequestException('Webhook returned HTTP ' . $response->getStatusCode());
        }
    }

    /**
     * @param list<array{title: string, value: string}> $facts
     * @return array<string, mixed>
     */
    private function message(array $facts): array
    {
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        ['type' => 'TextBlock', 'text' => 'Access request', 'weight' => 'Bolder', 'size' => 'Medium'],
                        ['type' => 'FactSet', 'facts' => $facts],
                    ],
                ],
            ]],
        ];
    }
}
