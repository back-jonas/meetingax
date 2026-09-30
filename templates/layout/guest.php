<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrf) ?>">
    <title><?= e($title ?? 'Meetingax') ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body>
    <header class="top">
        <a class="brand" href="<?= e(url('/')) ?>">Meetingax</a>
        <nav>
            <a href="<?= e(url('/join')) ?>">Anslut</a>
            <a href="<?= e(url('/login')) ?>">Logga in</a>
        </nav>
    </header>
    <main class="wrap wrap-narrow">
        <?php require __DIR__ . '/../partials/flash.php'; ?>
        <?= $content ?>
    </main>
</body>
</html>
