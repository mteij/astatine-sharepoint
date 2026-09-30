<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, string> $committees
 * @var string $selected
 * @var string $note
 * @var int $maxNote
 */
$this->assign('title', 'Request access');
?>
<div class="head">
    <h2>Request access</h2>
</div>

<div class="card">
    <p class="muted">Your request goes to the board channel together with your name and email.</p>
    <?php if ($committees === []) : ?>
        <p>There are no committees you can request access to right now.</p>
        <a class="btn" href="<?= $this->Url->build('/') ?>">Back</a>
    <?php else : ?>
        <?= $this->Form->create(null, ['url' => '/request-access', 'class' => 'stack']) ?>
            <?= $this->Form->control('committee', [
                'type' => 'select',
                'label' => 'Committee',
                'options' => $committees,
                'empty' => 'Select a committee',
                'default' => $selected,
                'required' => true,
            ]) ?>
            <?= $this->Form->control('note', [
                'type' => 'textarea',
                'label' => 'Note (optional)',
                'value' => $note,
                'rows' => 4,
                'maxlength' => $maxNote,
            ]) ?>
            <div class="actions">
                <button class="btn primary" type="submit">Send request</button>
                <a class="btn" href="<?= $this->Url->build('/') ?>">Cancel</a>
            </div>
        <?= $this->Form->end() ?>
    <?php endif; ?>
</div>
