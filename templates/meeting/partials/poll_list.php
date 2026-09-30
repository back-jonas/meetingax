<?php if (($polls ?? []) === []): ?>
    <p class="muted">Inga omröstningar ännu.</p>
<?php else: ?>
    <div class="stack">
        <?php foreach ($polls as $poll): ?>
            <article class="card poll-card">
                <div class="page-head">
                    <h3><?= e($poll['title']) ?></h3>
                    <span class="badge badge-<?= e($poll['status']) ?>"><?= e(poll_status_label($poll['status'])) ?></span>
                </div>
                <?php if (!empty($poll['description'])): ?>
                    <p><?= nl2e($poll['description']) ?></p>
                <?php endif; ?>
                <p class="muted"><?= e(voting_type_label((string) ($poll['voting_type'] ?? ''))) ?></p>
                <?php if ($poll['status'] !== 'closed' && !empty($poll['options'])): ?>
                    <ul class="option-preview">
                        <?php foreach ($poll['options'] as $option): ?>
                            <li><?= e($option['label']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p>Avgivna röster: <strong data-poll-count="<?= e($poll['public_id']) ?>"><?= (int) $poll['submitted_count'] ?></strong></p>
                <p class="muted">
                    <?= (int) $poll['show_results_to_participants'] === 1
                        ? 'Deltagarna får se resultatet när omröstningen stängts.'
                        : 'Deltagarna får inte se resultatfördelningen.' ?>
                </p>
                <?php if ($poll['status'] === 'open'): ?>
                    <p class="muted">Fördelningen visas först när omröstningen stängts.</p>
                <?php endif; ?>
                <?php if ($poll['status'] === 'closed' && !empty($poll['results'])): ?>
                    <div data-admin-results="1">
                        <?php foreach ($poll['results'] as $row): ?>
                            <p data-result-row="<?= e($row['option_key']) ?>">
                                <?= e($row['label']) ?>: <?= (int) $row['votes'] ?>
                                (<?= e(format_percent((float) $row['percent'])) ?> %)
                            </p>
                        <?php endforeach; ?>
                        <p>Röstberättigade: <?= (int) $poll['turnout']['eligible'] ?></p>
                        <p>Avgivna röster: <?= (int) $poll['turnout']['submitted'] ?></p>
                        <p>Ej röstat: <?= (int) $poll['turnout']['not_voted'] ?></p>
                        <p>Deltagande: <?= e(format_percent((float) $poll['turnout']['participation_percent'])) ?> %</p>
                    </div>
                <?php endif; ?>
                <?php if ($meeting['status'] !== 'archived' && $poll['status'] !== 'closed'): ?>
                    <form method="post" action="<?= e(url('/meeting/' . $meeting['public_id'] . '/votes/' . $poll['public_id'])) ?>">
                        <?php require __DIR__ . '/../../partials/csrf.php'; ?>
                        <?php if ($poll['status'] === 'draft' && $meeting['status'] === 'open'): ?>
                            <button class="btn btn-primary" name="action" value="open" type="submit">Öppna omröstning</button>
                        <?php elseif ($poll['status'] === 'draft'): ?>
                            <p class="muted">Öppna mötet innan omröstningen kan startas.</p>
                        <?php elseif ($poll['status'] === 'open'): ?>
                            <button class="btn btn-danger" name="action" value="close" type="submit">Stäng omröstning</button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
