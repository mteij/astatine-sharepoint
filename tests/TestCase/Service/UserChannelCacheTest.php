<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\UserChannelCache;
use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;

class UserChannelCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('portal');
    }

    public function testNothingRememberedYet(): void
    {
        $this->assertNull((new UserChannelCache())->load('u1'));
    }

    public function testRoundTripKeepsChannelsAndAccessPerUser(): void
    {
        $cache = new UserChannelCache();
        $channels = [['teamId' => 't1', 'teamName' => 'T', 'channelId' => 'c1', 'name' => 'ATAC']];
        $access = ['c1' => ['ok' => true, 'at' => 5]];
        $cache->save('u1', $channels, $access);

        $this->assertSame(['channels' => $channels, 'access' => $access], $cache->load('u1'));
        $this->assertNull($cache->load('u2'));
    }

    public function testCorruptEntryIsIgnored(): void
    {
        Cache::write('channels_' . hash('sha256', 'u1'), 'garbage', 'portal');

        $this->assertNull((new UserChannelCache())->load('u1'));
    }
}
