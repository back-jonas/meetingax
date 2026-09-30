<h1>Logga in</h1>
<?php require __DIR__ . '/../partials/errors.php'; ?>
<form method="post" action="<?= e(url('/login')) ?>" class="stack">
    <?php require __DIR__ . '/../partials/csrf.php'; ?>
    <input type="hidden" name="next" value="<?= e($next ?? '') ?>">
    <label>E-post
        <input type="email" name="email" autocomplete="username" required maxlength="255">
    </label>
    <label>Lösenord
        <input type="password" name="password" autocomplete="current-password" required maxlength="200">
    </label>
    <button class="btn btn-primary" type="submit">Logga in</button>
</form>
<p class="muted">Inget konto? <a href="<?= e(url('/register')) ?>">Skapa ett</a></p>
