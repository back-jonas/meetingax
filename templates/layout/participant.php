<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrf) ?>">
    <title><?= e($title ?? 'Möte') ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body class="participant"<?= !empty($signature) || !empty($screen['signature']) ? ' data-poll="participant" data-signature="' . e($signature ?? $screen['signature']) . '"' : '' ?>>
    <main class="wrap wrap-narrow">
        <?php require __DIR__ . '/../partials/flash.php'; ?>
        <?= $content ?>
    </main>
    <script src="<?= e(url('/assets/js/participant.js')) ?>"></script>
</body>
</html>
