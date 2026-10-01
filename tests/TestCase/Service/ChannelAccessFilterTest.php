<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ChannelAccessFilter;
use Cake\TestSuite\TestCase;

class ChannelAccessFilterTest extends TestCase
{
    private const NOW = 1000;

    /**
     * @return array{teamId: string, teamName: string, channelId: string, name: string, private: bool}
     */
    private function channel(string $name, bool $private): array
    {
        return ['teamId' => 't1', 'teamName' => 'T', 'channelId' => "c-{$name}", 'name' => $name, 'private' => $private];
    }

    /**
     * @param array<string, bool> $answers
     * @param list<string> $probed Filled with the channel ids that were probed.
     */
    private function filter(array $answers, array &$probed): ChannelAccessFilter
    {
        return new ChannelAccessFilter(static function (array $targets) use ($answers, &$probed): array {
            $probed = array_column($targets, 'channelId');

            return array_intersect_key($answers, array_flip($probed));
        });
    }

    public function testOnlyPrivateChannelsAreProbedAndRefusedOnesDropped(): void
    {
        $probed = [];
        [$channels, $known] = $this->filter(['c-Board' => false, 'c-Mine' => true], $probed)->filter(
            [$this->channel('ATAC', false), $this->channel('Board', true), $this->channel('Mine', true)],
            [],
            self::NOW,
        );

        $this->assertSame(['c-Board', 'c-Mine'], $probed);
        $this->assertSame(['ATAC', 'Mine'], array_column($channels, 'name'));
        $this->assertSame(['ok' => false, 'at' => self::NOW], $known['c-Board']);
    }

    public function testKnownAnswersAreNotProbedAgain(): void
    {
        $probed = ['untouched'];
        [$channels] = $this->filter([], $probed)->filter(
            [$this->channel('Board', true), $this->channel('Mine', true)],
            ['c-Board' => ['ok' => false, 'at' => self::NOW - 10], 'c-Mine' => ['ok' => true, 'at' => 1]],
            self::NOW,
        );

        $this->assertSame(['untouched'], $probed);
        $this->assertSame(['Mine'], array_column($channels, 'name'));
    }

    public function testRefusedChannelIsCheckedAgainAfterTheTtl(): void
    {
        $probed = [];
        [$channels] = $this->filter(['c-Board' => true], $probed)->filter(
            [$this->channel('Board', true)],
            ['c-Board' => ['ok' => false, 'at' => self::NOW - 301]],
            self::NOW,
        );

        $this->assertSame(['c-Board'], $probed);
        $this->assertSame(['Board'], array_column($channels, 'name'));
    }

    public function testChannelWithoutAnswerStaysVisible(): void
    {
        $probed = [];
        [$channels] = $this->filter([], $probed)->filter([$this->channel('Mine', true)], [], self::NOW);

        $this->assertSame(['Mine'], array_column($channels, 'name'));
    }

    public function testRefusedMarksChannel(): void
    {
        $probed = [];
        $known = $this->filter([], $probed)->refused([], 'c-Board', self::NOW);

        $this->assertSame(['c-Board' => ['ok' => false, 'at' => self::NOW]], $known);
    }
}
