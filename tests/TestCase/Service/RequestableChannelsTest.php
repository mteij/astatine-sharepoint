<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\RequestableChannels;
use Cake\TestSuite\TestCase;

class RequestableChannelsTest extends TestCase
{
    private const CHANNELS = [
        ['teamId' => 't1', 'teamName' => 'Astatine', 'channelId' => 'c1', 'name' => 'KasCo'],
        ['teamId' => 't1', 'teamName' => 'Astatine', 'channelId' => 'c2', 'name' => 'ATAC'],
        ['teamId' => 't1', 'teamName' => 'Astatine', 'channelId' => 'c3', 'name' => 'Board'],
    ];

    public function testOnlyAllowListedChannelsAreKept(): void
    {
        $requestable = new RequestableChannels(['c1', 'c3'], []);

        $this->assertSame(['c1', 'c3'], array_column($requestable->allowListed(self::CHANNELS), 'channelId'));
    }

    public function testEmptyAllowListAllowsNothing(): void
    {
        $this->assertSame([], (new RequestableChannels([], []))->allowListed(self::CHANNELS));
    }

    public function testJoinedChannelsAreLeftOutAndResultIsKeyedById(): void
    {
        $options = (new RequestableChannels(['c1', 'c2'], []))->notJoined(self::CHANNELS, ['c1']);

        $this->assertSame(['c2'], array_keys($options));
        $this->assertSame('ATAC', $options['c2']['name']);
    }
}
