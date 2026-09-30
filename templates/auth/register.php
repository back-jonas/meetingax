<h1>Skapa konto</h1>
<p class="muted">Kontot används av den som arrangerar mötet. Deltagare skapar inget konto.</p>
<?php require __DIR__ . '/../partials/errors.php'; ?>
<form method="post" action="<?= e(url('/register')) ?>" class="stack">
    <?php require __DIR__ . '/../partials/csrf.php'; ?>
    <label>Namn
        <input type="text" name="name" required maxlength="120" value="<?= e($name ?? '') ?>">
    </label>
    <label>E-post
        <input type="email" name="email" autocomplete="username" required maxlength="255" value="<?= e($email ?? '') ?>">
    </label>
    <label>Lösenord
        <input type="password" name="password" autocomplete="new-password" required minlength="10" maxlength="200">
    </label>
    <label>Upprepa lösenord
        <input type="password" name="password_confirmation" autocomplete="new-password" required minlength="10" maxlength="200">
    </label>
    <button class="btn btn-primary" type="submit">Skapa konto</button>
</form>
<p class="muted">Har du redan ett konto? <a href="<?= e(url('/login')) ?>">Logga in</a></p>
