# Serverovervågning

PHP/MariaDB-dashboard til fleet-telemetri. Recreation af Claude Design-mock'en
(`Server Monitor.dc.html` + `colors_and_type.css`), drevet af rigtige data fra
en agent på hver overvåget server.

## Arkitektur

- **PHP 8.2+, ingen framework.** PDO direkte mod MariaDB, prepared statements.
- **Session-cookie auth** til brugere (`src/Auth.php`) — se sikkerhedsafsnittet.
- **Per-host API-nøgle** til agenter (`public/api/ingest.php`) — separat fra brugerlogin.
- **Polling, ikke WebSockets.** `public/assets/js/dashboard.js` henter
  `/api/fleet.php` og `/api/host.php` hvert 2,2. sekund og gentegner DOM'en.
- Al præsentationslogik (statusfarver, tærskler, da-DK-formatering) ligger i
  `src/Presenter.php` / `src/StatusEngine.php` / `src/Formatting.php` og
  bruges af både den server-rendererede første visning (`dashboard.php`) og
  JSON-API'erne, så de aldrig kan komme ud af trit.

```
public/            ← webroot (peg webserverens document root herpå)
  index.php login.php logout.php dashboard.php
  api/fleet.php api/host.php api/ingest.php
  assets/css assets/js assets/fonts
  assets/vendor/    ← self-hosted Font Awesome + Chart.js (ingen CDN)
src/                ← PHP-klasser (Database, Auth, ServerRepository, Presenter, StatusEngine)
config/config.php   ← indlæser .env
sql/schema.sql
scripts/            ← CLI: create_admin.php, create_server.php, cleanup_metrics.php
```

## Opsætning

1. `composer install`
2. `cp .env.example .env` og udfyld DB-oplysninger.
3. `mysql -u <bruger> -p <database> < sql/schema.sql`
4. Opret en admin-bruger: `php scripts/create_admin.php <brugernavn> "Fulde Navn" admin`
   (spørger interaktivt om adgangskode).
5. Registrér hver overvåget server og gem dens API-nøgle:
   `php scripts/create_server.php <hostname> <ip> linux|windows [cpu_model] [kerner] [os_navn] [disk_gb] [ram_gb]`
   API-nøglen vises **kun én gang** — kun dens hash gemmes i databasen.
6. Pak `mount` webserverens **document root til `public/`**, ikke repo-roden
   (`.htaccess` i roden blokerer adgang som ekstra sikkerhed, hvis det sker alligevel).
7. Sæt `APP_FORCE_HTTPS=true` i produktion — login-cookien kræver det.
8. (Valgfrit) Cron: `php scripts/cleanup_metrics.php` hver time, rydder gamle målinger.

Lokal test uden Apache/nginx: `php -S 127.0.0.1:8000 -t public`.

## Agent-integration (ingest-API)

Agenten på hver server sender en `POST` til `/api/ingest.php` med sin nøgle i
`Authorization`-headeren — **ikke** en cookie, agenter er ikke browsere:

```
POST /api/ingest.php
Authorization: Bearer <api-nøgle fra create_server.php>
Content-Type: application/json

{
  "hostname": "web-01",
  "cpu_pct": 42.3, "cpu_temp_c": 56.4,
  "mem_pct": 57.5, "mem_used_gb": 18.4,
  "disk_used_pct": 74.0,
  "net_in_mbs": 4.2, "net_out_mbs": 2.1, "disk_io_mbs": 12.0,
  "fan_rpm": 1800, "uptime_seconds": 456000,
  "volumes": [{"mount": "/", "size_gb": 76, "used_pct": 74}],
  "processes": [{"name": "php-fpm: pool www", "user": "www-data", "pid": 1234,
                 "cpu_pct": 12.3, "mem_gb": 0.4, "disk_mbs": 0.1, "state": "Kører"}],
  "total_processes": 162
}
```

En vært regnes **offline** når der ikke er modtaget en måling inden for
`app_settings.offline_after_seconds` (standard 120 s) — så agenten bør sende
oftere end det, fx hvert 30.–60. sekund.

## Sikkerhed

- **Login: session-cookie, ikke en selv-udstedt token.** Cookien
  (`SMSESSID`) indeholder kun et tilfældigt session-ID; brugerdata ligger
  server-side. Flags: `HttpOnly`, `Secure` (når `APP_FORCE_HTTPS=true`),
  `SameSite=Strict`. `session_regenerate_id()` ved login. Absolut
  session-levetid 8 timer. Se `src/Auth.php`.
- **Adgangskoder:** `password_hash()` med Argon2id. Lockout efter 5 fejlede
  forsøg (15 min).
- **CSRF-token** på login-formularen (`Auth::csrfToken()` / `verifyCsrf()`).
- **Agent-auth er adskilt fra brugerlogin** — per-host API-nøgle, hashet i
  databasen, sendt som Bearer-token over HTTPS.
- **SQL:** udelukkende prepared statements via PDO.
- **Output:** alt brugerindhold escapes med `htmlspecialchars()` (PHP) /
  en tilsvarende `esc()`-funktion (JS) før det sættes i DOM'en — også
  procesnavne mv. fra agent-data, som i praksis er ekstern input.
- **Ingen ekstern font-/CDN-afhængighed:** Inter, Font Awesome og Chart.js er
  selv-hostet i `public/assets/` — ingen kald til Google Fonts eller andre
  tredjeparts-CDN'er ved sidevisning (GDPR: undgår IP-lækage til tredjepart).

## Ikke bygget endnu (bevidst udeladt — ikke designet)

Jf. handoff-README'en fra designet: "Konfigurér grænser"-dialogen,
rapporteksport, hændelseslog-visningen, søgefunktionen og login-fejlsider er
ikke en del af dette leverance. Knapperne er til stede i UI'et men deaktiverede.
