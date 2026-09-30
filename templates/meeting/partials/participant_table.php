<div class="table-wrap">
    <table class="data">
        <thead>
            <tr>
                <th>Namn</th>
                <th>E-post</th>
                <th>Uppgifter</th>
                <th>Anmäld</th>
                <th>Status</th>
                <th>Rösträtt</th>
                <th>Aktivitet</th>
                <th>Åtgärd</th>
            </tr>
        </thead>
        <tbody>
            <?php if (($participants ?? []) === []): ?>
                <tr><td colspan="8">Inga anmälningar ännu.</td></tr>
            <?php else: ?>
                <?php foreach ($participants as $person): ?>
                    <tr>
                        <td><?= e($person['name']) ?></td>
                        <td>
                            <?php if ($meeting['status'] !== 'archived' && in_array($person['status'], ['pending', 'approved'], true)): ?>
                                <form method="post" action="<?= e(url('/meeting/' . $meeting['public_id'] . '/participants/' . $person['public_id'])) ?>" class="email-edit">
                                    <?php require __DIR__ . '/../../partials/csrf.php'; ?>
                                    <input type="email" name="email" required maxlength="255" value="<?= e($person['email']) ?>" aria-label="E-post för <?= e($person['name']) ?>">
                                    <button class="btn btn-ghost" name="action" value="email" type="submit">Spara och skicka länk</button>
                                    <button class="btn btn-ghost" name="action" value="resend" type="submit">Skicka länken igen</button>
                                    <p class="muted">Den tidigare länken slutar fungera.</p>
                                </form>
                            <?php else: ?>
                                <?= e($person['email']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($person['fields'] === []): ?>
                                <span class="muted">–</span>
                            <?php else: ?>
                                <?php foreach ($person['fields'] as $field): ?>
                                    <div><?= e($field['label']) ?>: <?= e($field['value']) ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e(Meetingax\Support\Dates::formatDateTime((string) $person['registered_at'])) ?></td>
                        <td><span class="badge badge-<?= e($person['status']) ?>"><?= e(participant_status_label($person['status'])) ?></span></td>
                        <td><?= (int) $person['is_voting_eligible'] === 1 ? 'Ja' : 'Nej' ?></td>
                        <td data-presence="<?= e($person['public_id']) ?>"><?= e($person['presence_label']) ?></td>
                        <td>
                            <?php if ($meeting['status'] !== 'archived' && $person['status'] !== 'removed'): ?>
                                <form method="post" action="<?= e(url('/meeting/' . $meeting['public_id'] . '/participants/' . $person['public_id'])) ?>" class="actions">
                                    <?php require __DIR__ . '/../../partials/csrf.php'; ?>
                                    <?php if ($person['status'] === 'pending'): ?>
                                        <button class="btn btn-primary" name="action" value="approve" type="submit">Godkänn</button>
                                        <button class="btn btn-ghost" name="action" value="reject" type="submit">Avslå</button>
                                    <?php elseif ($person['status'] === 'approved'): ?>
                                        <?php if ((int) $person['is_voting_eligible'] === 1): ?>
                                            <button class="btn btn-ghost" name="action" value="revoke" type="submit">Ta bort rösträtt</button>
                                        <?php else: ?>
                                            <button class="btn btn-primary" name="action" value="grant" type="submit">Ge rösträtt</button>
                                        <?php endif; ?>
                                        <button class="btn btn-danger" name="action" value="remove" type="submit">Ta bort</button>
                                    <?php else: ?>
                                        <button class="btn btn-ghost" name="action" value="remove" type="submit">Ta bort</button>
                                    <?php endif; ?>
                                </form>
                            <?php else: ?>
                                <span class="muted">–</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
