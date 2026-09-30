<h1>Skapa möte</h1>
<?php require __DIR__ . '/../partials/errors.php'; ?>
<form method="post" action="<?= e(url('/meeting/create')) ?>" class="stack card">
    <?php require __DIR__ . '/../partials/csrf.php'; ?>
    <label>Namn
        <input type="text" name="title" required maxlength="200" value="<?= e($titleValue ?? '') ?>">
    </label>
    <label>Beskrivning
        <textarea name="description" maxlength="5000" rows="4"><?= e($description ?? '') ?></textarea>
    </label>
    <label>Datum
        <input type="date" name="meeting_date" value="<?= e($meetingDate ?? '') ?>">
    </label>
    <button class="btn btn-primary" type="submit">Skapa möte</button>
</form>
