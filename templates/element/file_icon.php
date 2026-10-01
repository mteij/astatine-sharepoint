<?php
/**
 * Small icon: a folder, or a coloured badge with the file extension.
 *
 * @var \App\View\AppView $this
 * @var string $kind folder, pdf, word, excel, powerpoint, onenote, image, archive, audio, video, text or file
 * @var string $extension Shown on the badge of a file, e.g. "xlsx".
 */
$extension = $extension ?? '';
?>
<?php if ($kind === 'folder') : ?>
    <svg class="ico ico-folder" viewBox="0 0 24 24" aria-hidden="true"><path class="back" d="M2 6.5A2.5 2.5 0 0 1 4.5 4h4.2c.5 0 1 .2 1.3.6L11.4 6H19.500A2.5 2.5 0 0 1 22 8.500v9A2.5 2.5 0 0 1 19.500 20h-15A2.5 2.5 0 0 1 2 17.500z"/><path class="front" d="M2 9.500A2.500 2.500 0 0 1 4.500 7h15A2.500 2.500 0 0 1 22 9.500v8a2.500 2.500 0 0 1-2.500 2.500h-15A2.500 2.500 0 0 1 2 17.500z"/></svg>
<?php else : ?>
    <span class="ico ico-file ico-<?= h($kind) ?>" aria-hidden="true"><?= h(strtoupper(mb_substr($extension, 0, 4))) ?></span>
<?php endif; ?>
