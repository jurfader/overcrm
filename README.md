# OVERCRM

Uniwersalny CRM dla małych i średnich firm B2B. Self-hosted u klienta + walidacja przez serwer licencji OVERMEDIA.

Fork z `planner-v2` (Chicken King Family). Roadmap przebudowy: [`PLAN_PRZEBUDOWY.md`](PLAN_PRZEBUDOWY.md).

## Stack

- Laravel 12 (PHP 8.2)
- Vue 3 + Inertia.js
- MySQL
- AI: Gemini (cloud) lub LM Studio Gemma + Whisper.cpp (lokalnie)
- Single-tenant per deploy, polski UI

## Setup deweloperski

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# stwórz pustą bazę MySQL i uzupełnij DB_* w .env
php artisan migrate
php artisan storage:link
npm run dev
```

**Nie odpalaj `php artisan db:seed` na produkcji** — seedery zawierają tylko dane demo.

## Licencjonowanie

Wymaga ważnego klucza licencyjnego z serwera OVERMEDIA. Skonfiguruj w `.env`:

```
OVERCRM_LICENSE_KEY=XXXX-XXXX-XXXX-XXXX
LICENSE_SERVER_URL=http://51.38.137.199:3002
OVERCRM_INSTALL_ID=<unique-uuid>
APP_DOMAIN=<your-domain>
```

Walidacja co 24h, grace period 7 dni przy braku łączności z serwerem licencji.
