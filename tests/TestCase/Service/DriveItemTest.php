<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\DriveItem;
use Cake\TestSuite\TestCase;

class DriveItemTest extends TestCase
{
    private function file(string $name): DriveItem
    {
        return DriveItem::fromGraph(['name' => $name, 'size' => 1, 'webUrl' => 'u']);
    }

    public function testFoldersAreFolders(): void
    {
        $folder = DriveItem::fromGraph(['name' => 'Reports 2026', 'folder' => ['childCount' => 3], 'webUrl' => 'u']);

        $this->assertSame('folder', $folder->kind());
        $this->assertSame('File folder', $folder->typeLabel());
        $this->assertSame(3, $folder->childCount);
    }

    public function testFolderWithDotInNameStaysAFolder(): void
    {
        $folder = DriveItem::fromGraph(['name' => 'archive.pdf', 'folder' => ['childCount' => 0], 'webUrl' => 'u']);

        $this->assertSame('folder', $folder->kind());
    }

    public function testKnownExtensionsGetKindAndLabel(): void
    {
        $this->assertSame(['pdf', 'PDF document'], [$this->file('a.pdf')->kind(), $this->file('a.pdf')->typeLabel()]);
        $this->assertSame('word', $this->file('Notulen.DOCX')->kind());
        $this->assertSame('excel', $this->file('begroting.xlsx')->kind());
        $this->assertSame('powerpoint', $this->file('deck.pptx')->kind());
        $this->assertSame('image', $this->file('foto.JPG')->kind());
    }

    public function testUnknownExtensionFallsBackToGenericFile(): void
    {
        $this->assertSame(['file', 'DWG file'], [$this->file('plan.dwg')->kind(), $this->file('plan.dwg')->typeLabel()]);
    }

    public function testNoExtensionIsAPlainFile(): void
    {
        $this->assertSame(['file', 'File'], [$this->file('README')->kind(), $this->file('README')->typeLabel()]);
        $this->assertSame('File', $this->file('.hidden')->typeLabel());
    }

    public function testFolderWithoutChildCountIsNull(): void
    {
        $this->assertNull(DriveItem::fromGraph(['name' => 'x', 'folder' => [], 'webUrl' => 'u'])->childCount);
        $this->assertNull($this->file('a.pdf')->childCount);
    }
}
