<?php $section = 'participants'; require __DIR__ . '/partials/nav.php'; ?>
<div class="page-head">
    <h1>Deltagare</h1>
    <p class="muted"><?= e($meeting['title']) ?> · <code><?= e($meeting['meeting_code']) ?></code></p>
</div>
<?php require __DIR__ . '/partials/summary.php'; ?>
<div data-fragment="participants">
    <?php require __DIR__ . '/partials/participant_table.php'; ?>
</div>
