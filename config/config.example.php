<?php

declare(strict_types=1);

/**
 * Kopiera till config.php och fyll i databasuppgifterna.
 * config.php ska inte checkas in.
 */
return [
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=meetingax;charset=utf8mb4',
        'user' => 'meetingax',
        'password' => 'byt-mig',
    ],
    'session' => [
        'name' => 'MX_SESSION',
        // Sätt true när webbplatsen enbart nås via HTTPS.
        'secure' => false,
    ],
    'participant_cookie' => [
        'name' => 'MX_PARTICIPANT',
        'lifetime' => 43200,
        'secure' => false,
    ],
    // Ska vara false i drift. Interna fel visas ändå aldrig i webbsvaret.
    'debug' => false,
];
