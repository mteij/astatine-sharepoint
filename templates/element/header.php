<?php
/**
 * Orange page header. Identical on every page; "Sign out" only appears when signed in.
 *
 * @var \App\View\AppView $this
 * @var array{name: string}|null $authUser
 */

use Cake\Core\Configure;

$authUser ??= null;
?>
<header class="top">
    <a class="back" href="<?= h(Configure::read('Portal.webappsUrl')) ?>">&lt; Back to webapps</a>
    <h1><a href="<?= $this->Url->build('/') ?>">SharePoint</a></h1>
    <?php if ($authUser) : ?>
        <?= $this->Form->create(null, ['url' => '/logout', 'class' => 'signout']) ?>
            <span class="who" title="<?= h($authUser['name']) ?>"><?= h($authUser['name']) ?></span>
            <button type="submit">Sign out</button>
        <?= $this->Form->end() ?>
    <?php endif; ?>
</header>
