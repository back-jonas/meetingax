<?php $section = 'show'; require __DIR__ . '/partials/nav.php'; ?>
<div class="page-head">
    <div>
        <h1><?= e($meeting['title']) ?></h1>
        <?php if (!empty($meeting['description'])): ?>
            <p><?= nl2e($meeting['description']) ?></p>
        <?php endif; ?>
    </div>
    <span class="badge badge-<?= e($meeting['status']) ?>"><?= e(meeting_status_label($meeting['status'])) ?></span>
</div>
<?php require __DIR__ . '/partials/summary.php'; ?>
<section class="card code-card">
    <p class="muted">Möteskod</p>
    <p class="code" id="meeting-code" data-meeting-code="<?= e($meeting['meeting_code']) ?>"><?= e($meeting['meeting_code']) ?></p>
    <button class="btn btn-ghost" type="button" data-copy="#meeting-code">Kopiera kod</button>
    <p class="muted">Deltagare ansluter via koden. Anmälningar tas emot först när mötet är öppet.</p>
</section>
<section class="card stack">
    <h2>Mötesstatus</h2>
    <?php if ($meeting['status'] === 'draft'): ?>
        <form method="post" action="<?= e(url('/meeting/' . $meeting['public_id'] . '/status')) ?>">
            <?php require __DIR__ . '/../partials/csrf.php'; ?>
            <input type="hidden" name="status" value="open">
            <button class="btn btn-primary" type="submit">Öppna mötet</button>
        </form>
    <?php elseif ($meeting['status'] === 'open'): ?>
        <form method="post" action="<?= e(url('/meeting/' . $meeting['public_id'] . '/status')) ?>">
            <?php require __DIR__ . '/../partials/csrf.php'; ?>
            <input type="hidden" name="status" value="closed">
            <button class="btn btn-danger" type="submit">Stäng mötet</button>
        </form>
    <?php elseif ($meeting['status'] === 'closed'): ?>
        <form method="post" action="<?= e(url('/meeting/' . $meeting['public_id'] . '/status')) ?>" class="inline">
            <?php require __DIR__ . '/../partials/csrf.php'; ?>
            <button class="btn btn-primary" name="status" value="open" type="submit">Öppna igen</button>
            <button class="btn btn-ghost" name="status" value="archived" type="submit">Arkivera</button>
        </form>
    <?php else: ?>
        <p>Mötet är arkiverat och kan inte längre ändras.</p>
    <?php endif; ?>
</section>
<section class="card stack">
    <h2>Egna registreringsfält</h2>
    <?php if ($fields === []): ?>
        <p class="muted">Inga extra fält. Namn och e-post krävs alltid.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($fields as $field): ?>
                <li>
                    <?= e($field['label']) ?>
                    <span class="muted">(<?= e($field['field_type']) ?><?= (int) $field['required'] === 1 ? ', obligatoriskt' : '' ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if (in_array($meeting['status'], ['draft', 'open'], true)): ?>
        <form method="post" action="<?= e(url('/meeting/' . $meeting['public_id'] . '/fields')) ?>" class="stack">
            <?php require __DIR__ . '/../partials/csrf.php'; ?>
            <label>Rubrik
                <input type="text" name="label" required maxlength="120" placeholder="Till exempel medlemsnummer">
            </label>
            <label>Typ
                <select name="field_type" data-field-type>
                    <option value="text">Text</option>
                    <option value="number">Tal</option>
                    <option value="select">Lista</option>
                </select>
            </label>
            <label>Obligatoriskt
                <input type="checkbox" name="required" value="1">
            </label>
            <label data-field-options>Alternativ, ett per rad
                <textarea name="options" rows="4" placeholder="Används bara för lista"></textarea>
            </label>
            <button class="btn btn-primary" type="submit">Lägg till fält</button>
        </form>
    <?php endif; ?>
</section>
<section class="card">
    <h2>Händelselogg</h2>
    <div data-fragment="audit">
        <?php require __DIR__ . '/partials/audit_list.php'; ?>
    </div>
</section>
