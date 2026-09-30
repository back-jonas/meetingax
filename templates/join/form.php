<h1>Anslut till möte</h1>
<p class="muted">Skriv in möteskoden, till exempel SUN-7K4P. Det spelar ingen roll om du använder stora eller små bokstäver.</p>
<?php require __DIR__ . '/../partials/errors.php'; ?>
<form method="post" action="<?= e(url('/join')) ?>" class="stack">
    <?php require __DIR__ . '/../partials/csrf.php'; ?>
    <label>Möteskod
        <input class="code-input" type="text" name="meeting_code" required maxlength="16" autocapitalize="characters" autocomplete="off" value="<?= e($code ?? '') ?>">
    </label>
    <button class="btn btn-primary" type="submit">Fortsätt</button>
</form>
