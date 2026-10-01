<?php
/**
 * The committees as folders in a details view, like a file explorer.
 *
 * @var \App\View\AppView $this
 * @var list<\App\Service\Channel> $channels
 * @var bool $showTeam
 * @var string $emptyMessage
 */
?>
<?php if ($channels === []) : ?>
    <p class="muted pad"><?= h($emptyMessage) ?></p>
<?php else : ?>
    <table class="files" id="committee-list">
        <thead>
            <tr>
                <th aria-sort="ascending"><button type="button" data-sort="name">Name</button></th>
                <?php if ($showTeam) : ?>
                    <th class="col-team"><button type="button" data-sort="team">Team</button></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($channels as $channel) : ?>
                <tr data-filter-item data-folder="1"
                    data-name="<?= h(mb_strtolower($channel->name)) ?>"
                    data-team="<?= h(mb_strtolower($channel->teamName)) ?>">
                    <td class="cell-name">
                        <?= $this->element('file_icon', ['kind' => 'folder']) ?>
                        <a class="name" href="<?= $this->Url->build(['action' => 'browse', $channel->slug]) ?>"><?= h($channel->name) ?></a>
                    </td>
                    <?php if ($showTeam) : ?>
                        <td class="muted"><?= h($channel->teamName) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
