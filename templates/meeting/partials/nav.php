<nav class="subnav">
    <a href="<?= e(url('/meeting/' . $meeting['public_id'])) ?>"<?= ($section ?? '') === 'show' ? ' aria-current="page"' : '' ?>>Möte</a>
    <a href="<?= e(url('/meeting/' . $meeting['public_id'] . '/participants')) ?>"<?= ($section ?? '') === 'participants' ? ' aria-current="page"' : '' ?>>Deltagare</a>
    <a href="<?= e(url('/meeting/' . $meeting['public_id'] . '/votes')) ?>"<?= ($section ?? '') === 'votes' ? ' aria-current="page"' : '' ?>>Omröstningar</a>
</nav>
