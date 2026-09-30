<ul class="log">
    <?php if (($events ?? []) === []): ?>
        <li class="muted">Inga händelser ännu.</li>
    <?php else: ?>
        <?php foreach ($events as $event): ?>
            <li>
                <time datetime="<?= e((string) $event['created_at']) ?>"><?= e(Meetingax\Support\Dates::formatTime((string) $event['created_at'])) ?></time>
                <?= e(Meetingax\Domain\AuditLog::label($event)) ?>
            </li>
        <?php endforeach; ?>
    <?php endif; ?>
</ul>
