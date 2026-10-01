<?php
declare(strict_types=1);

namespace App\Test\TestCase\View;

use App\Service\Channel;
use App\Service\DriveItem;
use App\Service\FolderPath;
use App\View\AppView;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;

class BrowseTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadRoutes();
    }

    /**
     * @param list<\App\Service\DriveItem> $items
     */
    private function render(array $items, FolderPath $path, ?string $error = null): string
    {
        $request = new ServerRequest([
            'url' => '/c/atac',
            'params' => ['controller' => 'Committees', 'action' => 'browse', 'plugin' => null],
        ]);
        Router::setRequest($request);
        $view = new AppView($request);
        $view->setTemplatePath('Committees');
        $view->set([
            'channel' => new Channel('atac', 'ATAC', 'Astatine', 't1', 'c1'),
            'path' => $path,
            'items' => $items,
            'folderUrl' => 'https://example.sharepoint.com/f',
            'error' => $error,
        ]);

        return $view->render('browse', false);
    }

    /**
     * @return list<\App\Service\DriveItem>
     */
    private function items(): array
    {
        return [
            DriveItem::fromGraph(['name' => 'Notulen', 'folder' => ['childCount' => 1], 'webUrl' => 'u1']),
            DriveItem::fromGraph(['name' => 'Archief', 'folder' => ['childCount' => 12], 'webUrl' => 'u2']),
            DriveItem::fromGraph(['name' => 'Begroting.xlsx', 'size' => 2048, 'webUrl' => 'u3', 'lastModifiedDateTime' => '2026-01-02T10:00:00Z']),
            DriveItem::fromGraph(['name' => 'Jaarplan <2026>.pdf', 'size' => 5, 'webUrl' => 'u4']),
        ];
    }

    public function testShowsTypeIconsCountsAndSortableColumns(): void
    {
        $html = $this->render($this->items(), FolderPath::root());

        $this->assertStringContainsString('ico-folder', $html);
        $this->assertStringContainsString('ico-excel', $html);
        $this->assertStringContainsString('ico-pdf', $html);
        $this->assertStringContainsString('1 item', $html);
        $this->assertStringContainsString('12 items', $html);
        $this->assertStringContainsString('File folder', $html);
        $this->assertStringContainsString('Excel spreadsheet', $html);
        $this->assertStringContainsString('>4 items<', $html);
        $this->assertStringContainsString('title="12 items"', $html);
        $this->assertStringContainsString('data-sort="modified"', $html);
        $this->assertStringContainsString('data-folder="1"', $html);
    }

    public function testFileNamesAreEscaped(): void
    {
        $html = $this->render($this->items(), FolderPath::root());

        $this->assertStringContainsString('Jaarplan &lt;2026&gt;.pdf', $html);
        $this->assertStringNotContainsString('<2026>', $html);
    }

    public function testUpLinkGoesToTheOverviewAtTheRoot(): void
    {
        $html = $this->render($this->items(), FolderPath::root());

        $this->assertMatchesRegularExpression('#class="btn up" href="/"#', $html);
    }

    public function testUpLinkGoesToTheParentFolder(): void
    {
        $html = $this->render($this->items(), FolderPath::fromSegments(['Reports', 'Q1']));

        $this->assertMatchesRegularExpression('#class="btn up" href="/c/atac/Reports"#', $html);
    }

    public function testDeepPathCollapsesTheMiddleFolders(): void
    {
        $html = $this->render($this->items(), FolderPath::fromSegments(['One', 'Two', 'Three', 'Four', 'Five', 'Six']));

        $this->assertStringContainsString('title="One / Two / Three / Four"', $html);
        $this->assertStringContainsString('href="/c/atac/One/Two/Three/Four"', $html);
        $this->assertMatchesRegularExpression('#<a href="/c/atac/One/Two/Three/Four/Five"[^>]*>Five</a>#', $html);
        $this->assertMatchesRegularExpression('#<strong title="Six">Six</strong>#', $html);
        $this->assertDoesNotMatchRegularExpression('#<a href="/c/atac/One"[^>]*>One</a>#', $html);
    }

    public function testShortPathShowsEveryFolder(): void
    {
        $html = $this->render($this->items(), FolderPath::fromSegments(['One', 'Two', 'Three']));

        $this->assertStringNotContainsString('…', $html);
        $this->assertMatchesRegularExpression('#<a href="/c/atac/One"[^>]*>One</a>#', $html);
        $this->assertMatchesRegularExpression('#<strong title="Three">Three</strong>#', $html);
    }

    public function testDateAndSizeAreAlsoListedUnderTheName(): void
    {
        $html = $this->render($this->items(), FolderPath::root());

        $this->assertMatchesRegularExpression('#class="meta">[^<]*· 2.05 KB#', $html);
    }

    public function testEmptyFolderAndErrorHaveNoTable(): void
    {
        $this->assertStringContainsString('This folder is empty.', $this->render([], FolderPath::root()));
        $this->assertStringNotContainsString('<table', $this->render([], FolderPath::root()));
        $this->assertStringContainsString('Try again later', $this->render([], FolderPath::root(), 'Try again later'));
    }
}
