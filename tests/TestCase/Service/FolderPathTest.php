<?php

declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\FolderPath;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

class FolderPathTest extends TestCase
{
    public function testRootIsEmpty(): void
    {
        $this->assertTrue(FolderPath::root()->isRoot());
        $this->assertSame('', FolderPath::root()->encoded());
    }

    public function testParseIgnoresEmptySegments(): void
    {
        $this->assertSame(['a', 'b'], FolderPath::parse('/a//b/')->segments());
        $this->assertTrue(FolderPath::parse('')->isRoot());
    }

    /**
     * @dataProvider invalidSegments
     */
    public function testRejectsUnsafeSegments(string $segment): void
    {
        $this->expectException(InvalidArgumentException::class);
        FolderPath::fromSegments(['ok', $segment]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSegments(): array
    {
        return [
            'dot' => ['.'],
            'parent' => ['..'],
            'slash' => ['a/b'],
            'backslash' => ['a\\b'],
            'colon' => ['a:b'],
            'control char' => ["a\x00b"],
        ];
    }

    public function testEncodesEachSegment(): void
    {
        $this->assertSame('Finance%20%23%201/2026', FolderPath::fromSegments(['Finance # 1', '2026'])->encoded());
    }

    public function testUrlUnderAppendsEncodedPath(): void
    {
        $base = 'https://x.sharepoint.com/sites/s/Shared%20Documents/ATAC/';

        $this->assertSame(rtrim($base, '/'), FolderPath::root()->urlUnder($base));
        $this->assertSame(
            'https://x.sharepoint.com/sites/s/Shared%20Documents/ATAC/Minutes/Q1%20%232',
            FolderPath::fromSegments(['Minutes', 'Q1 #2'])->urlUnder($base),
        );
    }

    public function testJoinDoesNotMutate(): void
    {
        $base = FolderPath::parse('General');
        $joined = $base->join(FolderPath::parse('Sub/Deep'));

        $this->assertSame(['General'], $base->segments());
        $this->assertSame(['General', 'Sub', 'Deep'], $joined->segments());
    }

    public function testBreadcrumbsAreCumulative(): void
    {
        $crumbs = FolderPath::parse('a/b/c')->breadcrumbs();

        $this->assertSame(['a', 'b', 'c'], array_column($crumbs, 'name'));
        $this->assertSame(['a', 'b'], $crumbs[1]['path']->segments());
    }
}
