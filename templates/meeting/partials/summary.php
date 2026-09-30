<section class="stat-grid" aria-label="Översikt">
    <div class="card stat">
        <span class="muted">Status</span>
        <strong data-meeting-status><?= e(meeting_status_label($meeting['status'])) ?></strong>
    </div>
    <div class="card stat">
        <span class="muted">Väntar</span>
        <strong data-count="pending"><?= (int) $state['counts']['pending'] ?></strong>
    </div>
    <div class="card stat">
        <span class="muted">Godkända</span>
        <strong data-count="approved"><?= (int) $state['counts']['approved'] ?></strong>
    </div>
    <div class="card stat">
        <span class="muted">Röstberättigade</span>
        <strong data-count="voting_eligible"><?= (int) $state['counts']['voting_eligible'] ?></strong>
    </div>
</section>
