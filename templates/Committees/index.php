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

<div id="committees" data-url="<?= $this->Url->build('/committees') ?>" data-login="<?= $this->Url->build('/login') ?>">
    <?= $this->element('committee_tiles') ?>
</div>
<noscript><p class="muted">Turn on JavaScript to load your committees.</p></noscript>
<?= $this->Html->script('committees', ['block' => true, 'defer' => true]) ?>
