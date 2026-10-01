<?php
/**
 * @var \App\View\AppView $this
 * @var list<\App\Service\Channel> $channels
 * @var bool $showTeam
 * @var string $emptyMessage
 */
?>
<?php if ($channels === []) : ?>
    <p class="muted"><?= h($emptyMessage) ?></p>
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
