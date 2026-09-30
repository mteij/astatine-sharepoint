<?php
/**
 * @var \App\View\AppView $this
 * @var list<\App\Service\Channel> $channels
 * @var bool $showTeam
 * @var string $requestAccessUrl
 */
$this->assign('title', 'Your committees');
?>
<div class="head">
    <h2>Your committees</h2>
    <a class="btn primary" href="<?= h($requestAccessUrl) ?>">Request access</a>
</div>

<?php if ($channels === []) : ?>
    <p class="muted">You are not in any committee yet.</p>
<?php else : ?>
    <ul class="tiles">
        <?php foreach ($channels as $channel) : ?>
            <li>
                <a class="tile" href="<?= $this->Url->build(['action' => 'browse', $channel->slug]) ?>">
                    <span>
                        <strong><?= h($channel->name) ?></strong>
                        <?php if ($showTeam) : ?>
                            <small><?= h($channel->teamName) ?></small>
                        <?php endif; ?>
                    </span>
                    <span class="chev" aria-hidden="true">›</span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
