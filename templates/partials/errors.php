<?php if (!empty($errors)): ?>
    <div class="flash flash-err">
        <?php foreach ($errors as $error): ?>
            <p><?= e((string) $error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <p class="flash flash-err"><?= e((string) $error) ?></p>
<?php endif; ?>
