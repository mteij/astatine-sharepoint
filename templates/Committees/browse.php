<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Service\Channel $channel
 * @var \App\Service\FolderPath $path
 * @var list<\App\Service\DriveItem> $items
 * @var string|null $folderUrl
 * @var string|null $error
 */
$this->assign('title', $channel->name);
$link = fn (array $segments): string => $this->Url->build(['action' => 'browse', $channel->slug, ...$segments]);
$last = count($path->segments()) - 1;
$up = $path->isRoot() ? $this->Url->build('/') : $link(array_slice($path->segments(), 0, -1));
$folders = count(array_filter($items, static fn ($i): bool => $i->isFolder));
$files = count($items) - $folders;
$crumbs = $path->breadcrumbs();
// Deep paths keep the address bar on one line: the middle folders collapse into a link to the nearest hidden one.
$hidden = count($crumbs) > 3 ? array_slice($crumbs, 0, count($crumbs) - 2) : [];
$visible = array_slice($crumbs, count($hidden), null, true);
$counts = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
?>
<div class="explorer" tabindex="0">
    <div class="toolbar">
        <a class="btn up" href="<?= h($up) ?>" title="Up one level (Backspace)" aria-label="Up one level">↑</a>
        <nav class="address" aria-label="Folder path">
            <?= $this->element('file_icon', ['kind' => 'folder']) ?>
            <a href="<?= $this->Url->build('/') ?>">Committees</a>
            <span class="sep">›</span>
            <?php if ($path->isRoot()) : ?>
                <strong><?= h($channel->name) ?></strong>
            <?php else : ?>
                <a href="<?= $link([]) ?>"><?= h($channel->name) ?></a>
                <?php if ($hidden !== []) : ?>
                    <span class="sep">›</span>
                    <a href="<?= $link(end($hidden)['path']->segments()) ?>" title="<?= h(implode(' / ', array_column($hidden, 'name'))) ?>">…</a>
                <?php endif; ?>
                <?php foreach ($visible as $i => $crumb) : ?>
                    <span class="sep">›</span>
                    <?php if ($i === $last) : ?>
                        <strong title="<?= h($crumb['name']) ?>"><?= h($crumb['name']) ?></strong>
                    <?php else : ?>
                        <a href="<?= $link($crumb['path']->segments()) ?>" title="<?= h($crumb['name']) ?>"><?= h($crumb['name']) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>
        <?php if ($items !== []) : ?>
            <input class="filter" type="search" placeholder="Filter" aria-label="Filter this folder"
                data-filter-scope="#files" data-filter-empty="#files-empty" data-filter-count="#files-status">
        <?php endif; ?>
        <?php if ($folderUrl) : ?>
            <a class="btn primary sp" href="<?= h($folderUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open this folder in SharePoint (new tab)">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
                Open in SharePoint
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($error)) : ?>
        <p class="muted pad"><?= h($error) ?></p>
    <?php elseif ($items === []) : ?>
        <p class="muted pad">This folder is empty.</p>
    <?php else : ?>
        <table class="files" id="files">
            <thead>
                <tr>
                    <th aria-sort="ascending"><button type="button" data-sort="name">Name</button></th>
                    <th class="col-date hide-sm"><button type="button" data-sort="modified">Date modified</button></th>
                    <th class="col-type hide-sm"><button type="button" data-sort="type">Type</button></th>
                    <th class="col-size num"><button type="button" data-sort="size">Size</button></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr data-filter-item data-folder="<?= $item->isFolder ? '1' : '0' ?>"
                        data-name="<?= h(mb_strtolower($item->name)) ?>"
                        data-modified="<?= $item->modified ? $item->modified->getTimestamp() : 0 ?>"
                        data-type="<?= h(mb_strtolower($item->typeLabel())) ?>"
                        data-size="<?= $item->isFolder ? 0 : ($item->size ?? 0) ?>">
                        <?php
                        $date = $item->modified ? $item->modified->format('d-m-Y H:i') : '';
                        $size = !$item->isFolder && $item->size !== null ? $this->Number->toReadableSize($item->size) : '';
                        ?>
                        <td class="cell-name">
                            <?= $this->element('file_icon', ['kind' => $item->kind(), 'extension' => $item->extension()]) ?>
                            <span class="entry">
                            <?php if ($item->isFolder) : ?>
                                <a class="name" href="<?= $link([...$path->segments(), $item->name]) ?>"<?= $item->childCount === null ? '' : ' title="' . h($counts($item->childCount, 'item', 'items')) . '"' ?>><?= h($item->name) ?></a>
                            <?php else : ?>
                                <a class="name" href="<?= h($item->webUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($item->name) ?></a>
                            <?php endif; ?>
                                <small class="meta"><?= h(trim($date . ($date !== '' && $size !== '' ? ' · ' : '') . $size)) ?></small>
                            </span>
                        </td>
                        <td class="hide-sm muted"><?= h($date) ?></td>
                        <td class="hide-sm muted"><?= h($item->typeLabel()) ?></td>
                        <td class="col-size muted num"><?= h($size) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted pad" id="files-empty" hidden>No items match your filter.</p>
        <p class="status" id="files-status"><?= h($counts(count($items), 'item', 'items')) ?></p>
    <?php endif; ?>
</div>
<?= $this->Html->script('explorer', ['block' => true, 'defer' => true]) ?>
