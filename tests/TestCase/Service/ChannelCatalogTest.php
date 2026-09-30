<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ChannelCatalog;
use Cake\TestSuite\TestCase;

class ChannelCatalogTest extends TestCase
{
    private function channel(string $name, string $teamId = 't1'): array
    {
        return ['teamId' => $teamId, 'teamName' => "Team {$teamId}", 'channelId' => "c-{$name}-{$teamId}", 'name' => $name];
    }

    public function testSlugsAreUrlSafe(): void
    {
        $catalog = new ChannelCatalog([$this->channel('Kas Co & Friends')]);

        $this->assertNotNull($catalog->find('kas-co-friends'));
    }

    public function testDuplicateNamesGetUniqueSlugs(): void
    {
        $catalog = new ChannelCatalog([$this->channel('KasCo', 't1'), $this->channel('KasCo', 't2')]);

        $this->assertSame('t1', $catalog->find('kasco')->teamId);
        $this->assertSame('t2', $catalog->find('kasco-2')->teamId);
    }

    public function testUnknownSlugIsNull(): void
    {
        $this->assertNull((new ChannelCatalog([]))->find('nope'));
    }

    public function testDetectsMultipleTeams(): void
    {
        $this->assertFalse((new ChannelCatalog([$this->channel('A'), $this->channel('B')]))->spansMultipleTeams());
        $this->assertTrue((new ChannelCatalog([$this->channel('A', 't1'), $this->channel('B', 't2')]))->spansMultipleTeams());
    }
}
