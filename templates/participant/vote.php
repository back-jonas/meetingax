<p class="eyebrow"><?= e($meeting['meeting_code']) ?></p>
<h1><?= e($meeting['title']) ?></h1>
<p class="muted">Inloggad som <?= e($participant['name']) ?></p>
<div data-live>
    <?php if ($screen['mode'] === 'not_eligible'): ?>
        <p class="status-banner">Du är godkänd som deltagare men har inte rösträtt.</p>
        <?php if (!empty($screen['poll'])): ?>
            <h2><?= e($screen['poll']['title']) ?></h2>
        <?php endif; ?>
    <?php elseif ($screen['mode'] === 'vote'): ?>
        <p class="status-banner open">Omröstning öppen</p>
        <h2><?= e($screen['poll']['title']) ?></h2>
        <?php if (!empty($screen['poll']['description'])): ?>
            <p><?= nl2e($screen['poll']['description']) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/m/' . $meeting['meeting_code'] . '/vote')) ?>" class="vote-actions">
            <?php require __DIR__ . '/../partials/csrf.php'; ?>
            <input type="hidden" name="poll_public_id" value="<?= e($screen['poll']['public_id']) ?>">
            <?php foreach ($screen['options'] as $option): ?>
                <?php
                $class = match ($option['option_key']) {
                    'yes' => 'btn-yes',
                    'no' => 'btn-no',
                    'abstain' => 'btn-abstain',
                    default => 'btn-primary',
                };
                ?>
                <button class="btn vote-btn <?= e($class) ?>" name="option" value="<?= e($option['option_key']) ?>" type="submit"><?= e($option['label']) ?></button>
            <?php endforeach; ?>
        </form>
    <?php elseif ($screen['mode'] === 'voted'): ?>
        <p class="status-banner ok" role="status">Din röst har registrerats.</p>
        <?php if (!empty($screen['poll'])): ?>
            <h2><?= e($screen['poll']['title']) ?></h2>
        <?php endif; ?>
        <?php if (!empty($screen['own_choice'])): ?>
            <p>Ditt val: <?= e($screen['own_choice']) ?></p>
        <?php endif; ?>
        <p class="muted">Rösten kan inte ändras.</p>
    <?php elseif ($screen['mode'] === 'results'): ?>
        <p class="status-banner">Omröstning stängd</p>
        <h2><?= e($screen['poll']['title']) ?></h2>
        <div data-results-panel="1">
            <?php foreach ($screen['results'] as $row): ?>
                <p class="result-line" data-result-row="<?= e($row['option_key']) ?>">
                    <?= e($row['label']) ?>: <?= (int) $row['votes'] ?>
                    (<?= e(format_percent((float) $row['percent'])) ?> %)
                </p>
            <?php endforeach; ?>
            <p>Röstberättigade: <?= (int) $screen['turnout']['eligible'] ?></p>
            <p>Avgivna röster: <?= (int) $screen['turnout']['submitted'] ?></p>
            <p>Ej röstat: <?= (int) $screen['turnout']['not_voted'] ?></p>
        </div>
    <?php elseif ($screen['mode'] === 'results_hidden'): ?>
        <p class="status-banner">Omröstningen är avslutad.</p>
        <?php if (!empty($screen['poll'])): ?>
            <h2><?= e($screen['poll']['title']) ?></h2>
        <?php endif; ?>
        <p>Resultatet visas inte för deltagarna.</p>
    <?php else: ?>
        <p class="status-banner pulse" data-waiting>Väntar på nästa omröstning.</p>
    <?php endif; ?>
</div>
<p class="muted">Sidan uppdateras automatiskt.</p>
