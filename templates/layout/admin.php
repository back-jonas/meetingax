<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrf) ?>">
    <title><?= e($title ?? 'Meetingax') ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body<?= !empty($state) ? ' data-poll="admin" data-meeting="' . e($meeting['public_id']) . '" data-signature="' . e($state['signature']) . '"' : '' ?>>
    <header class="top">
        <a class="brand" href="<?= e(url('/dashboard')) ?>">Meetingax</a>
        <nav>
            <?php if (!empty($adminUser)): ?>
                <span class="muted"><?= e($adminUser['name']) ?></span>
                <form method="post" action="<?= e(url('/logout')) ?>" class="inline">
                    <?php require __DIR__ . '/../partials/csrf.php'; ?>
                    <button class="btn btn-ghost" type="submit">Logga ut</button>
                </form>
            <?php endif; ?>
        </nav>
    </header>
    <main class="wrap">
        <?php require __DIR__ . '/../partials/flash.php'; ?>
        <?= $content ?>
    </main>
    <script src="<?= e(url('/assets/js/admin.js')) ?>"></script>
</body>
</html>
