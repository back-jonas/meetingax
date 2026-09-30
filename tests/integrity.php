<?php

declare(strict_types=1);

/**
 * Kontrollerar databasregler och fas 1-flödet mot meetingax_test.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use Meetingax\Database\Connection;
use Meetingax\Database\Migrator;
use Meetingax\Domain\AppException;
use Meetingax\Domain\AuditLog;
use Meetingax\Domain\BallotService;
use Meetingax\Domain\FieldService;
use Meetingax\Domain\MeetingService;
use Meetingax\Domain\MeetingStateService;
use Meetingax\Domain\ParticipantService;
use Meetingax\Domain\PollService;
use Meetingax\Domain\UserService;
use Meetingax\Support\Tokens;

$config = meetingax_config();
$config['db']['dsn'] = preg_replace('/dbname=[^;]+/', 'dbname=meetingax_test', $config['db']['dsn']);
$pdo = Connection::make($config['db']);
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with($database, '_test')) {
    fwrite(STDERR, "Vägrar köra tester mot {$database}.\n");
    exit(1);
}

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "OK {$message}\n";
        return;
    }
    $failures++;
    echo "FEL {$message}\n";
}

function expect_exception(callable $fn, string $code, string $message): void
{
    try {
        $fn();
        check(false, $message . ' (inget undantag)');
    } catch (AppException $e) {
        check($e->errorCode === $code, $message . " ({$e->errorCode})");
    }
}

function expect_sql_fail(PDO $pdo, string $sql, array $params, string $message): void
{
    try {
        $pdo->prepare($sql)->execute($params);
        check(false, $message . ' (frågan lyckades)');
    } catch (PDOException $e) {
        $errno = (int) ($e->errorInfo[1] ?? 0);
        check(in_array($errno, [1452, 1062], true), $message . " ({$errno})");
    }
}

function reset_database(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    (new Migrator($pdo, dirname(__DIR__) . '/database/migrations'))->migrate();
}

reset_database($pdo);

$pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')->execute(['A', 'a@example.com', 'x']);
$userId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO meetings (public_id, owner_user_id, title, meeting_code, status) VALUES (?,?,?,?,?)')->execute(['AAAAAAAAAAAA', $userId, 'Möte A', 'AAA-2222', 'open']);
$meetingA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO meetings (public_id, owner_user_id, title, meeting_code, status) VALUES (?,?,?,?,?)')->execute(['BBBBBBBBBBBB', $userId, 'Möte B', 'BBB-3333', 'open']);
$meetingB = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO polls (public_id, meeting_id, title, created_by_user_id) VALUES (?,?,?,?)')->execute(['POLLAAAAAAA1', $meetingA, 'P1', $userId]);
$pollA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO polls (public_id, meeting_id, title, created_by_user_id) VALUES (?,?,?,?)')->execute(['POLLBBBBBBB2', $meetingB, 'P2', $userId]);
$pollB = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO poll_options (poll_id, option_key, label, sort_order) VALUES (?,?,?,?)')->execute([$pollA, 'yes', 'JA', 1]);
$optionA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO poll_options (poll_id, option_key, label, sort_order) VALUES (?,?,?,?)')->execute([$pollB, 'yes', 'JA', 1]);
$optionB = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO participants (public_id, meeting_id, name, email, status, is_voting_eligible) VALUES (?,?,?,?,?,?)')->execute(['PARTAAAAAAA1', $meetingA, 'Anna', 'anna@example.com', 'approved', 1]);
$participantA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO participants (public_id, meeting_id, name, email, status, is_voting_eligible) VALUES (?,?,?,?,?,?)')->execute(['PARTBBBBBBB2', $meetingB, 'Bo', 'bo@example.com', 'approved', 1]);
$participantB = (int) $pdo->lastInsertId();

expect_sql_fail($pdo, 'INSERT INTO meeting_open_poll (meeting_id, poll_id) VALUES (?, ?)', [$meetingA, $pollB], 'öppen omröstning från annat möte stoppas');
$pdo->prepare('INSERT INTO meeting_open_poll (meeting_id, poll_id) VALUES (?, ?)')->execute([$meetingA, $pollA]);
expect_sql_fail($pdo, 'INSERT INTO meeting_open_poll (meeting_id, poll_id) VALUES (?, ?)', [$meetingA, $pollA], 'en andra öppen omröstning stoppas');
expect_sql_fail($pdo, 'INSERT INTO open_ballots (meeting_id, poll_id, participant_id, poll_option_id) VALUES (?,?,?,?)', [$meetingA, $pollA, $participantA, $optionB], 'alternativ från annan omröstning stoppas');
expect_sql_fail($pdo, 'INSERT INTO open_ballots (meeting_id, poll_id, participant_id, poll_option_id) VALUES (?,?,?,?)', [$meetingA, $pollA, $participantB, $optionA], 'deltagare från annat möte stoppas');
$pdo->prepare('INSERT INTO open_ballots (meeting_id, poll_id, participant_id, poll_option_id) VALUES (?,?,?,?)')->execute([$meetingA, $pollA, $participantA, $optionA]);
expect_sql_fail($pdo, 'INSERT INTO open_ballots (meeting_id, poll_id, participant_id, poll_option_id) VALUES (?,?,?,?)', [$meetingA, $pollA, $participantA, $optionA], 'dubbelröst stoppas');
$secretColumns = $pdo->query('SHOW COLUMNS FROM secret_ballots')->fetchAll(PDO::FETCH_COLUMN);
check(!in_array('participant_id', $secretColumns, true), 'sluten röstsedel saknar participant_id');

reset_database($pdo);

$users = new UserService($pdo);
$meetings = new MeetingService($pdo);
$fields = new FieldService($pdo);
$participants = new ParticipantService($pdo, 43200);
$polls = new PollService($pdo);
$ballots = new BallotService($pdo);
$state = new MeetingStateService($pdo);

$ownerId = $users->register('Mötesledare', 'ledare@example.com', 'ett-langt-losenord');
$otherId = $users->register('Annan', 'annan@example.com', 'ett-annat-losenord');
$meeting = $meetings->create($ownerId, 'Årsmöte', 'Beskrivning', '2026-09-30');
check($meetings->findForUser($meeting['public_id'], $otherId) === null, 'annan användare ser inte mötet');
check($meetings->findByCode(strtolower($meeting['meeting_code']))['id'] === $meeting['id'], 'möteskod är skiftlägesokänslig');
check(Tokens::isMeetingCode($meeting['meeting_code']), 'möteskoden har rätt format');

$fields->add($meeting, 'Kommun', 'select', true, "Uppsala\nLund", $ownerId);
expect_exception(
    fn () => $participants->register($meeting, 'Anna', 'anna@example.com', []),
    'MEETING_NOT_OPEN',
    'anmälan kräver öppet möte'
);
$meetings->setStatus($meeting, 'open', $ownerId);
$meeting = $meetings->findForUser($meeting['public_id'], $ownerId);

$extracted = $fields->extract((int) $meeting['id'], ['kommun' => 'Uppsala']);
check($extracted['errors'] === [], 'giltigt listvärde godkänns');
$bad = $fields->extract((int) $meeting['id'], ['kommun' => '']);
check($bad['errors'] !== [], 'obligatoriskt fält krävs');

$registered = $participants->register($meeting, 'Anna Andersson', 'Anna@example.com', $extracted['values']);
$participant = $registered['participant'];
check($participant['status'] === 'pending', 'ny deltagare är pending');
check((int) $participant['is_voting_eligible'] === 0, 'rösträtt ges inte vid anmälan');
$sessionCount = (int) $pdo->query('SELECT COUNT(*) FROM participant_sessions')->fetchColumn();
check($sessionCount === 1, 'session skapas vid registrering');
check(hash('sha256', $registered['token']) === (string) $pdo->query('SELECT token_hash FROM participant_sessions')->fetchColumn(), 'bara hashen sparas');

expect_exception(fn () => $ballots->cast((int) $participant['id'], 'yes'), 'NOT_APPROVED', 'pending får inte rösta');
$participants->approve($meeting, $participant, $ownerId);
expect_exception(fn () => $ballots->cast((int) $participant['id'], 'yes'), 'NOT_ELIGIBLE', 'godkänd utan rösträtt får inte rösta');

$poll = $polls->create($meeting, $ownerId, 'Styrelsens förslag', null, true);
$polls->open($meeting, $poll, $ownerId);
check(true, 'omröstning kan öppnas innan någon har rösträtt');
$second = $polls->create($meeting, $ownerId, 'Andra förslaget', null, true);
expect_exception(fn () => $polls->open($meeting, $second, $ownerId), 'POLL_ALREADY_OPEN', 'bara en öppen omröstning');

$participants->grantVote($meeting, $participant, $ownerId);
$ballots->cast((int) $participant['id'], 'yes', $poll['public_id']);
expect_exception(fn () => $ballots->cast((int) $participant['id'], 'no', $poll['public_id']), 'ALREADY_VOTED', 'andra rösten stoppas');

$participant['status'] = 'approved';
$participant['is_voting_eligible'] = 1;
$participant['meeting_status'] = 'open';
$screen = $state->participantScreen($participant);
check($screen['mode'] === 'voted', 'deltagaren ser att rösten registrerats');
check($screen['results'] === null, 'ingen resultatfördelning medan omröstningen är öppen');
$adminJson = json_encode($state->adminState($meeting), JSON_THROW_ON_ERROR);
check(!str_contains($adminJson, 'percent') && !str_contains($adminJson, 'option_key'), 'admin-API visar inte fördelning');

$events = (new AuditLog($pdo))->forMeeting((int) $meeting['id']);
$ballotEvents = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'BALLOT_SUBMITTED'));
check(count($ballotEvents) === 1, 'rösten loggas');
$ballotJson = (string) $ballotEvents[0]['event_data_json'];
check(!str_contains($ballotJson, 'yes') && !str_contains($ballotJson, 'option'), 'auditloggen saknar valet');
check(AuditLog::label($ballotEvents[0]) === 'En röst registrerades', 'loggen visar inte vem som röstade vad');

$polls->close($meeting, $poll, $ownerId);
$screen = $state->participantScreen($participant);
check($screen['mode'] === 'results', 'deltagaren ser resultat efter stängning');
check((int) $screen['results'][0]['votes'] === 1, 'JA räknas');

$pdo->prepare('UPDATE polls SET visibility = ? WHERE id = ?')->execute(['secret', $second['id']]);
$second = $polls->findInMeeting((int) $meeting['id'], $second['public_id']);
expect_exception(fn () => $polls->open($meeting, $second, $ownerId), 'UNSUPPORTED', 'sluten omröstning öppnas inte i MVP');

$hidden = $polls->create($meeting, $ownerId, 'Hemligt resultat', null, false);
$polls->open($meeting, $hidden, $ownerId);
$ballots->cast((int) $participant['id'], 'no', $hidden['public_id']);
$polls->close($meeting, $hidden, $ownerId);
$screen = $state->participantScreen($participant);
check($screen['mode'] === 'results_hidden' && $screen['results'] === null, 'deltagare ser inte dolda resultat');
$adminPolls = $polls->listForAdmin((int) $meeting['id']);
$hiddenRow = null;
foreach ($adminPolls as $row) {
    if ($row['public_id'] === $hidden['public_id']) {
        $hiddenRow = $row;
    }
}
check($hiddenRow !== null && isset($hiddenRow['results']), 'admin ser resultat efter stängning även om deltagare inte gör det');

expect_exception(
    fn () => $polls->create($meeting, $ownerId, 'För få', null, true, 'single_choice', 'Bara ett', false),
    'VALIDATION',
    'ett alternativ utan AVSTÅR stoppas'
);
expect_exception(
    fn () => $polls->create($meeting, $ownerId, 'Dubblett', null, true, 'single_choice', "Karin Holm\nkarin holm", true),
    'VALIDATION',
    'dubbletter bland alternativen stoppas'
);
expect_exception(
    fn () => $polls->create($meeting, $ownerId, 'Fel typ', null, true, 'person', '', false),
    'VALIDATION',
    'okänd omröstningstyp stoppas'
);

$person = $polls->create($meeting, $ownerId, 'Ordförande', null, true, 'single_choice', "Karin Holm\nBo Berg\nAVSTÅR", true);
$personOptions = $polls->options((int) $person['id']);
check(count($personOptions) === 3, 'personval får tre alternativ');
check($personOptions[0]['label'] === 'Karin Holm' && $personOptions[0]['option_key'] === 'opt1', 'första alternativet är Karin');
check($personOptions[1]['option_key'] === 'opt2' && $personOptions[2]['option_key'] === 'abstain', 'AVSTÅR läggs sist en gång');
$polls->open($meeting, $person, $ownerId);
expect_exception(
    fn () => $ballots->cast((int) $participant['id'], 'opt99', $person['public_id']),
    'VALIDATION',
    'okänt alternativ stoppas'
);
$ballots->cast((int) $participant['id'], 'opt1', $person['public_id']);
$screen = $state->participantScreen($participant);
check($screen['mode'] === 'voted' && $screen['own_choice'] === 'Karin Holm', 'deltagaren ser sitt personval');
check($screen['results'] === null, 'personval visar inte resultat medan det är öppet');
$adminJson = json_encode($state->adminState($meeting), JSON_THROW_ON_ERROR);
check(
    !str_contains($adminJson, 'Karin') && !str_contains($adminJson, 'opt1') && !str_contains($adminJson, 'percent'),
    'admin-API döljer personvalets fördelning'
);
expect_exception(
    fn () => $ballots->cast((int) $participant['id'], 'opt2', $person['public_id']),
    'ALREADY_VOTED',
    'andra personvalet stoppas'
);
$events = (new AuditLog($pdo))->forMeeting((int) $meeting['id']);
$ballotEvents = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'BALLOT_SUBMITTED'));
$lastBallot = (string) $ballotEvents[array_key_last($ballotEvents)]['event_data_json'];
check(!str_contains($lastBallot, 'Karin') && !str_contains($lastBallot, 'opt1'), 'auditloggen saknar personvalet');
$polls->close($meeting, $person, $ownerId);
$screen = $state->participantScreen($participant);
$karinVotes = null;
foreach ($screen['results'] as $row) {
    if ($row['option_key'] === 'opt1') {
        $karinVotes = (int) $row['votes'];
    }
}
check($karinVotes === 1, 'Karin får en röst');

$multi = $polls->create($meeting, $ownerId, 'Flera samtidigt', null, true);
$pdo->prepare('UPDATE polls SET voting_type = ? WHERE id = ?')->execute(['multiple_choice', $multi['id']]);
$multi = $polls->findInMeeting((int) $meeting['id'], $multi['public_id']);
expect_exception(fn () => $polls->open($meeting, $multi, $ownerId), 'UNSUPPORTED', 'flerval öppnas inte');

$other = $participants->register($meeting, 'Bo Berg', 'bo@example.com', $extracted['values']);
$participants->reject($meeting, $other['participant'], $ownerId);
$left = (int) $pdo->query('SELECT COUNT(*) FROM participant_sessions WHERE participant_id = ' . (int) $other['participant']['id'])->fetchColumn();
check($left === 0, 'avslag ogiltigförklarar sessionen');
$again = $participants->register($meeting, 'Bo Berg', 'bo@example.com', $extracted['values']);
check($again['participant']['status'] === 'pending', 'avslagen e-post kan anmälas på nytt');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} kontroller misslyckades.\n");
    exit(1);
}
echo "Alla integritetskontroller lyckades.\n";
