<p class="eyebrow"><?= e($meeting['meeting_code']) ?></p>
<h1><?= e($meeting['title']) ?></h1>
<?php if ($meeting['status'] !== 'open'): ?>
    <p class="status-banner" data-live>Mötet tar inte emot anmälningar just nu.</p>
<?php else: ?>
    <?php require __DIR__ . '/../partials/errors.php'; ?>
    <form method="post" action="<?= e(url('/m/' . $meeting['meeting_code'] . '/register')) ?>" class="stack">
        <?php require __DIR__ . '/../partials/csrf.php'; ?>
        <label>Namn
            <input type="text" name="name" required maxlength="160" value="<?= e($name ?? '') ?>">
        </label>
        <label>E-post
            <input type="email" name="email" required maxlength="255" value="<?= e($email ?? '') ?>">
        </label>
        <?php foreach ($fields as $field): ?>
            <label><?= e($field['label']) ?><?= (int) $field['required'] === 1 ? ' *' : '' ?>
                <?php $current = (string) ($postedFields[$field['field_key']] ?? ''); ?>
                <?php if ($field['field_type'] === 'select'): ?>
                    <select name="field[<?= e($field['field_key']) ?>]" <?= (int) $field['required'] === 1 ? 'required' : '' ?>>
                        <option value="">Välj</option>
                        <?php foreach ($field['options'] as $option): ?>
                            <option value="<?= e($option) ?>"<?= $current === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($field['field_type'] === 'number'): ?>
                    <input type="text" inputmode="decimal" name="field[<?= e($field['field_key']) ?>]" maxlength="100" value="<?= e($current) ?>" <?= (int) $field['required'] === 1 ? 'required' : '' ?>>
                <?php else: ?>
                    <input type="text" name="field[<?= e($field['field_key']) ?>]" maxlength="500" value="<?= e($current) ?>" <?= (int) $field['required'] === 1 ? 'required' : '' ?>>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">Anmäl mig</button>
    </form>
    <p class="muted">Du kommer in i mötet först när arrangören har godkänt dig.</p>
<?php endif; ?>
