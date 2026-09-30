<?php
/**
 * @var \App\View\AppView $this
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($this->fetch('title')) ?> · Astatine</title>
    <?= $this->element('fonts') ?>
    <?= $this->Html->css('app') ?>
</head>
<body>
<?= $this->element('header') ?>
<main class="wrap">
    <div class="card hero center">
        <?= $this->fetch('content') ?>
        <p><a href="<?= $this->Url->build('/') ?>" class="btn">Back to start</a></p>
    </div>
</main>
</body>
</html>
