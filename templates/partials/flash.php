<?php if (!empty($flash) && is_array($flash)): ?>
    <p class="flash flash-<?= e((string) ($flash['type'] ?? 'ok')) ?>"><?= e((string) ($flash['message'] ?? '')) ?></p>
<?php endif; ?>
