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
    // Publik adress som skrivs i mejlet. Tomt värde använder anropets värd.
    // Sätt den riktiga HTTPS-adressen när webbplatsen ligger bakom en proxy.
    'app' => [
        'url' => '',
    ],
    'mail' => [
        // log skriver mejlet till storage/mail. smtp skickar på riktigt.
        'transport' => 'log',
        'from_address' => 'noreply@example.com',
        'from_name' => 'Meetingax',
        // Sju dagar. Länken kan användas flera gånger tills den går ut.
        'return_link_lifetime' => 604800,
        'smtp' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
        ],
    ],
    // Ska vara false i drift. Interna fel visas ändå aldrig i webbsvaret.
    'debug' => false,
];
