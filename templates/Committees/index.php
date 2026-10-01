<?php
/**
 * @var \App\View\AppView $this
 * @var string $requestAccessUrl
 */
$this->assign('title', 'Your committees');
?>
<div class="head">
    <h2>Your committees</h2>
    <a class="btn primary" href="<?= h($requestAccessUrl) ?>">Request access</a>
</div>

<div class="explorer" tabindex="0">
    <div class="toolbar">
        <span class="address"><?= $this->element('file_icon', ['kind' => 'folder']) ?> <strong>Committees</strong></span>
        <input class="filter" type="search" placeholder="Filter" aria-label="Filter committees"
            data-filter-scope="#committees" data-filter-empty="#committees-empty">
    </div>
    <div id="committees" data-url="<?= $this->Url->build('/committees') ?>" data-login="<?= $this->Url->build('/login') ?>">
        <?= $this->element('committee_tiles') ?>
    </div>
    <p class="muted pad" id="committees-empty" hidden>No committees match your filter.</p>
</div>
<noscript><p class="muted">Turn on JavaScript to load your committees.</p></noscript>
<?= $this->Html->script(['committees', 'explorer'], ['block' => true, 'defer' => true]) ?>
