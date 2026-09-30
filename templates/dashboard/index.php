<div class="page-head">
    <h1>Mina möten</h1>
    <a class="btn btn-primary" href="<?= e(url('/meeting/create')) ?>">Skapa möte</a>
</div>
<?php if ($meetings === []): ?>
    <p class="card">Du har inga möten ännu.</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Möte</th>
                    <th>Kod</th>
                    <th>Datum</th>
                    <th>Status</th>
                    <th>Deltagare</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($meetings as $meeting): ?>
                    <tr>
                        <td><a href="<?= e(url('/meeting/' . $meeting['public_id'])) ?>"><?= e($meeting['title']) ?></a></td>
                        <td><code><?= e($meeting['meeting_code']) ?></code></td>
                        <td><?= e($meeting['meeting_date'] ?: '–') ?></td>
                        <td><span class="badge badge-<?= e($meeting['status']) ?>"><?= e(meeting_status_label($meeting['status'])) ?></span></td>
                        <td><?= (int) $meeting['approved_count'] ?> godkända<?php if ((int) $meeting['pending_count'] > 0): ?>, <?= (int) $meeting['pending_count'] ?> väntar<?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
