<?php $section = 'votes'; require __DIR__ . '/partials/nav.php'; ?>
<div class="page-head">
    <h1>Omröstningar</h1>
    <p class="muted"><?= e($meeting['title']) ?></p>
</div>
<?php require __DIR__ . '/partials/summary.php'; ?>
<?php if (in_array($meeting['status'], ['draft', 'open'], true)): ?>
    <form method="post" action="<?= e(url('/meeting/' . $meeting['public_id'] . '/votes')) ?>" class="card stack">
        <?php require __DIR__ . '/../partials/csrf.php'; ?>
        <h2>Ny omröstning</h2>
        <p class="muted">Bara en omröstning kan vara öppen åt gången. I ett eget val väljer deltagaren ett alternativ.</p>
        <label>Förslag
            <input type="text" name="title" required maxlength="200">
        </label>
        <label>Beskrivning
            <textarea name="description" maxlength="5000" rows="3"></textarea>
        </label>
        <label>Typ
            <select name="voting_type" data-voting-type>
                <option value="yes_no_abstain">JA, NEJ och AVSTÅR</option>
                <option value="single_choice">Eget val, välj ett (personval m.m.)</option>
            </select>
        </label>
        <div data-custom-options hidden>
            <label>Svarsalternativ, ett per rad
                <textarea name="options" maxlength="4000" rows="5" placeholder="Karin Holm&#10;Bo Berg"></textarea>
            </label>
            <label class="check">
                <input type="hidden" name="include_abstain" value="0">
                <input type="checkbox" name="include_abstain" value="1" checked>
                Lägg till AVSTÅR
            </label>
        </div>
        <label class="check">
            <input type="hidden" name="show_results_to_participants" value="0">
            <input type="checkbox" name="show_results_to_participants" value="1" checked>
            Visa resultatet för deltagarna när omröstningen stängts
        </label>
        <button class="btn btn-primary" type="submit">Skapa utkast</button>
    </form>
<?php endif; ?>
<div data-fragment="polls">
    <?php require __DIR__ . '/partials/poll_list.php'; ?>
</div>
