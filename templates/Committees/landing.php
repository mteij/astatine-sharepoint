<?php
/**
 * @var \App\View\AppView $this
 */
$this->assign('title', 'Sign in');
?>
<section class="card hero center">
    <h2>Committee files</h2>
    <p class="muted">Sign in with your university Microsoft account. You will see the committees you are a member of, and you can ask the board for access to others.</p>
    <p><a class="btn primary" href="<?= $this->Url->build('/login') ?>">Sign in with Microsoft</a></p>
</section>
