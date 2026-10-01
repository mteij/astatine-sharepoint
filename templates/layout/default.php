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
    <title><?= h($this->fetch('title') ?: 'SharePoint') ?> · Astatine</title>
    <?= $this->element('fonts') ?>
    <?= $this->Html->css('app') ?>
</head>
<body>
<?= $this->element('header') ?>
<main class="wrap">
    <?= $this->Flash->render() ?>
    <?= $this->fetch('content') ?>
</main>
<?= $this->fetch('script') ?>
</body>
</html>
