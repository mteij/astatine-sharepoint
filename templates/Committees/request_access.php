<?php
/**
 * @var \App\View\AppView $this
 * @var string $committee
 * @var string $note
 * @var int $maxCommittee
 * @var int $maxNote
 * @var string $fallbackUrl
 */
$this->assign('title', 'Request access');
?>
<div class="head">
    <h2>Request access</h2>
    <a class="btn" href="<?= $this->Url->build('/') ?>">Cancel</a>
</div>

<div class="card">
    <p class="muted">Your request goes to the board channel together with your name and email.</p>
    <?= $this->Form->create(null, ['url' => '/request-access', 'class' => 'stack']) ?>
        <?= $this->Form->control('committee', [
            'label' => 'Committee',
            'value' => $committee,
            'required' => true,
            'maxlength' => $maxCommittee,
        ]) ?>
        <?= $this->Form->control('note', [
            'type' => 'textarea',
            'label' => 'Note (optional)',
            'value' => $note,
            'rows' => 4,
            'maxlength' => $maxNote,
        ]) ?>
        <div>
            <button class="btn primary" type="submit">Send request</button>
            <a class="muted" href="<?= h($fallbackUrl) ?>">or email the board</a>
        </div>
    <?= $this->Form->end() ?>
</div>
