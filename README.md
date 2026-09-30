# Meetingax

Ett enkelt webbaserat omröstningssystem för möten, till exempel föreningsmöten, bolagsstämmor, styrelsemöten och årsmöten.

Första versionen är en medvetet liten MVP: konto för arrangören, möteskod, anmälan, godkännande, rösträtt samt öppna omröstningar. Arrangören kan välja JA, NEJ och AVSTÅR, eller ange egna svarsalternativ där deltagaren väljer ett, till exempel ett personval. Databasen är förberedd för fler omröstningstyper och för slutna omröstningar, men de flödena är inte byggda ännu.

## Krav

- PHP 8.1 eller nyare, med PDO och pdo_mysql
- MariaDB eller MySQL
- Apache eller nginx, med dokumentrot pekad på `public/`

## Kom igång

1. Skapa databas och användare:

```sql
CREATE DATABASE meetingax CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'meetingax'@'localhost' IDENTIFIED BY 'ett-langt-losenord';
GRANT ALL PRIVILEGES ON meetingax.* TO 'meetingax'@'localhost';
```

2. Kopiera `config/config.example.php` till `config/config.php` och fyll i uppgifterna. Sätt `session.secure` och `participant_cookie.secure` till `true` när webbplatsen bara nås via HTTPS.

3. Kör migrationerna:

```bash
php bin/migrate.php
```

4. Peka webbserverns dokumentrot på `public/`.

Apache behöver `AllowOverride` så att `public/.htaccess` kan skicka trafiken till `index.php`.

Exempel för nginx:

```nginx
root /var/www/meetingax/public;
index index.php;
location / {
    try_files $uri /index.php?$query_string;
}
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

Under utveckling kan PHP:s inbyggda server användas:

```bash
php -S localhost:8080 -t public public/router.php
```

## Så används systemet

Arrangören skapar ett konto, loggar in och skapar ett möte. Mötet får en kod i stil med `SUN-7K4P`. När mötet öppnas kan deltagare anmäla sig med namn, e-post och eventuella extra fält. De hamnar i vänteläge tills arrangören godkänner dem och, om de ska få rösta, ger dem rösträtt.

Vid anmälan skickas ett mejl med en personlig länk. Länken öppnar samma anmälan igen om deltagaren stänger webbläsaren, byter enhet eller tappar kakan. Den kan användas flera gånger i sju dagar och innehåller inget röstval. I utveckling skrivs mejlet till `storage/mail`. I drift sätter man `mail.transport` till `smtp` och `app.url` till webbplatsens adress.

Arrangören skapar en omröstning, öppnar den och stänger den. I ett eget val skrivs ett svarsalternativ per rad, och AVSTÅR kan läggas till. Deltagaren väljer ett alternativ. Sidan uppdateras själv. En röst kan bara lämnas en gång. Resultatfördelningen visas inte medan omröstningen pågår. När den stängts ser arrangören alltid resultatet. Deltagarna ser det bara om rutan om resultatvisning var ikryssad.

## Struktur

- `public/` är dokumentrot och front controller
- `src/Auth/` hanterar sessioner, CSRF och rate limiting
- `src/Database/` öppnar PDO och kör migrationer
- `src/Domain/` innehåller affärsreglerna
- `src/Http/` är router, sidor och JSON-API
- `templates/` är PHP-mallar
- `database/migrations/` är SQL-migrationer

Polling sker mot `/api/participant/state` och `/api/admin/meeting/state`. Samma domäntjänster kan senare matas av WebSockets eller server-sent events.

## Säkerhet

- Lösenord lagras med `password_hash()`
- Arrangörens session och deltagarens session är separata. Deltagartoken och återlänken sparas bara som SHA-256
- Alla ändrande anrop kräver CSRF-token
- Behörighet kontrolleras på servern mot `meeting_roles`
- Röster skrivs i en transaktion. En unik nyckel gör att samma deltagare inte kan lämna två röster
- Sammansatta främmande nycklar hindrar att en omröstning, ett alternativ eller en deltagare kopplas till fel möte
- Admin- och deltagar-API:t lämnar inte ut resultatfördelning medan omröstningen är öppen
- Auditloggen sparar aldrig det valda alternativet
- Oväntade fel loggas på servern och visas som ett generellt meddelande

Rate limiting använder `REMOTE_ADDR`. Bakom en reverse proxy behöver webbservern sätta den riktiga klientadressen, annars delar alla samma hink.

## Tester

```bash
php tests/integrity.php
```

Testerna använder databasen `meetingax_test` och samma användare som i `config.php`. De återskapar schemat och ska inte köras mot en databas vars namn inte slutar på `_test`.

## Det som inte ingår ännu

Slutna omröstningar, val där deltagaren kan välja flera alternativ samtidigt, rangordnade val, flera administratörer i gränssnittet, fullmakter, dagordning och protokoll. Tabellerna `vote_participation` och `secret_ballots` finns så att sluten omröstning kan läggas till utan att rösten och identiteten hamnar i samma rad. De används inte av MVP:n.
