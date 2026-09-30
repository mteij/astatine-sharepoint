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
?>
<div class="head">
    <h2><?= h($channel->name) ?></h2>
    <?php if ($folderUrl) : ?>
        <a class="btn primary" href="<?= h($folderUrl) ?>" target="_blank" rel="noopener noreferrer">Open in SharePoint ↗</a>
    <?php endif; ?>
</div>

<div class="bar">
    <a href="<?= $this->Url->build('/') ?>">Committees</a>
    <span class="sep">/</span>
    <?php if ($path->isRoot()) : ?>
        <strong><?= h($channel->name) ?></strong>
    <?php else : ?>
        <a href="<?= $link([]) ?>"><?= h($channel->name) ?></a>
        <?php foreach ($path->breadcrumbs() as $i => $crumb) : ?>
            <span class="sep">/</span>
            <?php if ($i === $last) : ?>
                <strong><?= h($crumb['name']) ?></strong>
            <?php else : ?>
                <a href="<?= $link($crumb['path']->segments()) ?>"><?= h($crumb['name']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <?php if (!empty($error)) : ?>
        <p class="muted"><?= h($error) ?></p>
    <?php elseif ($items === []) : ?>
        <p class="muted">This folder is empty.</p>
    <?php else : ?>
        <table class="files">
            <thead>
                <tr><th>Name</th><th class="hide-sm">Modified</th><th class="hide-sm num">Size</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td>
                            <?php if ($item->isFolder) : ?>
                                <a class="name folder" href="<?= $link([...$path->segments(), $item->name]) ?>"><?= h($item->name) ?></a>
                            <?php else : ?>
                                <a class="name" href="<?= h($item->webUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($item->name) ?></a>
                            <?php endif; ?>
                        </td>
                        <td class="hide-sm muted"><?= $item->modified ? h($item->modified->format('j M Y')) : '' ?></td>
                        <td class="hide-sm muted num"><?= !$item->isFolder && $item->size !== null ? h($this->Number->toReadableSize($item->size)) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
