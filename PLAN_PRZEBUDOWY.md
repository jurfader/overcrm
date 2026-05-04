# Plan przebudowy: planner-v2 → OVERCRM

**Uniwersalny CRM dla małych i średnich firm B2B.**

## Kontekst

- Fork z `planner-v2` (CRM Chicken King Family — dostawca panierki dla gastronomii B2B)
- Cel: produkt sprzedawany innym klientom (małe/średnie firmy B2B, niezależne od branży)
- Stack: Laravel 12 + Vue 3 + Inertia.js + MySQL + AI (Gemini cloud / LM Studio Gemma + Whisper.cpp lokalnie)
- Stan: pełny CRM produkcyjny, działający 8+ miesięcy. **Bardzo dużo hardkodów Chicken King** — głównie w promptach AI, kolorach marki, modułach Cenniki/Reports oraz integracjach PL-specific.

---

## ⚠️ Pierwsze kroki w nowym chacie

**Zanim Claude cokolwiek refaktoruje:**

1. `composer install`
2. `npm install`
3. `cp .env.example .env` + `php artisan key:generate`
4. Stwórz pustą bazę MySQL (np. `overcrm_dev`)
5. `php artisan migrate` (uważaj — niektóre migracje mogą zawierać CK hardkody, patrz checklist niżej)
6. **NIE** odpalaj `php artisan db:seed` zanim nie sprawdzisz czy seedery nie wstawiają CK content
7. `php artisan storage:link`
8. `npm run dev`

---

## Decyzje architektoniczne do podjęcia w pierwszej rozmowie

### 1. Single-tenant czy multi-tenant?

- **Single (rekomendacja na start)** — każdy klient = osobna instancja, osobna baza, osobny VPS/kontener. Prostsze, mniej ryzyka cross-tenant data leaks.
- Multi — wszyscy klienci w jednej bazie z `tenant_id`. Tańsze utrzymanie, ale dużo refactoru i więcej ryzyk bezpieczeństwa.

### 2. Onboarding nowego klienta

- Wizard (logo → kolory → AI persona → klucze API → admin user → done)
- Czy raczej deploy przez DevOps + ręczne ustawienia w Admin?

### 3. Język UI

- Polski jak teraz (najszybciej do MVP)
- Multi-lang pl/en od razu (większy refactor — ~1500 stringów do otagowania)

### 4. Model biznesowy

- Self-hosted u klienta (klient ma swój VPS)
- SaaS hostowany przez nas (subdomena per klient + billing)

---

## Etapy przebudowy (priorytet 1 → 5)

### Etap 1 — Branding extraction (PRIORYTET 1)

**Cel:** zero stringów "Chicken King", zero hardkodowanych kolorów marki w kodzie. Wszystko z `config/brand.php` + UI Settings → Branding.

#### Pliki do refaktoru

**Backend (PHP) — najgorsze hardkody:**
- `modules/Ringostat/src/Services/CallAiAnalyzer.php` — gigantyczny system prompt opisujący Chicken King, produkty, filozofię sprzedaży, KFC-style breading, listę produktów (Pakiet Startowy, Marynaty, Dobra Szama, frytury…)
- `modules/Ringostat/src/Services/GeminiCallAnalyzer.php` — to samo, drugi analyzer
- `modules/Leads/src/Services/LeadScoringService.php` — kontekst gastronomii (B2B chicken supplier)
- `modules/Leads/src/Services/GoogleMapsScraperService.php` — typy szukane: kebab, burgerownia, food truck
- `modules/Leads/src/Services/DeliveryPlatformScraperService.php` — Pyszne/Glovo/Uber/Wolt-specific
- `app/Services/CallReminderService.php` — kontekst rozmów handlowych
- `app/Services/Reports/MarginReportExporter.php` — kolory `#FFC000` + `#303030` w XLSX, hardkodowane logo
- `app/Services/VisitProfileAnalyzer.php` — prompt dla AI

**Frontend (Vue) — branding wizualny:**
- `resources/js/Layouts/AuthenticatedLayout.vue` — logo, nazwa, footer
- `resources/js/Pages/Auth/Login.vue` — login screen branding
- `resources/js/Pages/Auth/Register.vue`
- `resources/js/Pages/Welcome.vue`
- `resources/js/Pages/Dashboard.vue` — nagłówek, powitanie
- `resources/views/app.blade.php` — `<title>`, meta tags
- `public/favicon.ico`, ikony, logo
- `tailwind.config.js` — primary color w palette

**Konfiguracja:**
- `config/app.php` — `name`
- `.env.example` — `APP_NAME`, klucze API (wszystkie do wyzerowania)
- `config/changelog.json` — wyzeruj historię, fresh start jako "1.0.0"

**Email & dokumenty:**
- Email signature footer w `users.email_html_footer` (per-user, ale defaulty są CK)
- Email templates w bazie (sprawdź seedery `database/seeders/EmailTemplate*.php`)

#### Plan techniczny

1. Utwórz `config/brand.php`:
```php
return [
    'name' => env('BRAND_NAME', 'OVERCRM'),
    'short_name' => env('BRAND_SHORT_NAME', 'OVERCRM'),
    'primary_color' => env('BRAND_PRIMARY', '#3B82F6'),
    'secondary_color' => env('BRAND_SECONDARY', '#1F2937'),
    'logo_url' => env('BRAND_LOGO_URL', '/logo.svg'),
    'logo_dark_url' => env('BRAND_LOGO_DARK_URL', '/logo-dark.svg'),
    'support_email' => env('BRAND_SUPPORT_EMAIL', 'support@overcrm.app'),
    'support_phone' => env('BRAND_SUPPORT_PHONE', null),
];
```

2. **Inertia middleware** dodaje `brand` do każdego response — globalny prop dostępny w każdym Vue.

3. **Komponenty Vue:** `<BrandLogo />`, `<BrandName />`, `<BrandColor name="primary" />` lub po prostu CSS variables ustawiane w `<head>`.

4. **Settings UI:** Admin → Moduły → Branding — upload logo, picker kolorów, podgląd na żywo.

5. **CSS variables** w `app.blade.php`:
```html
<style>
  :root {
    --brand-primary: {{ config('brand.primary_color') }};
    --brand-secondary: {{ config('brand.secondary_color') }};
  }
</style>
```

Tailwind klasy `text-brand-primary`, `bg-brand-primary` przez `tailwind.config.js` z `colors.brand.primary = 'var(--brand-primary)'`.

---

### Etap 2 — AI Persona extraction (PRIORYTET 1)

**Cel:** AI prompts agnostyczne wobec branży. Kontekst firmy ładowany z bazy.

#### Co teraz hardcoded w promptach

System prompt CallAiAnalyzer zawiera (~50 linii):
- Nazwę firmy ("Chicken King Family")
- Branżę ("dostawca panierki dla gastronomii B2B")
- Listę produktów z cenami i pakami
- Filozofię "DOBRA rozmowa" / "ZŁA rozmowa" z konkretnymi przykładami pytań
- Korzyści produktu ("marża 70%", "chrupiący kurczak w 3 minuty")
- Target customer (fast food, kebab, burgerownia)

#### Plan

1. Tabela `ai_persona`:
```
- company_name
- company_description (long text)
- industry
- target_customer (text)
- products (json: name, price, description, key_benefits)
- value_propositions (json: array of strings)
- sales_philosophy_good (long text — kryteria DOBREJ rozmowy)
- sales_philosophy_bad (long text — kryteria ZŁEJ rozmowy)
- diagnostic_questions (json: example questions)
- typical_objections (json: objection → response)
- competitor_landscape (text)
- updated_at
```

Pojedynczy rekord, edytowalny przez Admin (nie multi-tenant chyba że Etap 7).

2. Admin → AI → Persona — bogaty edytor (textareas, repeatery na produkty/pytania).

3. `App\Services\AI\PromptBuilder`:
```php
class PromptBuilder {
    public function callAnalysisSystemPrompt(): string {
        $persona = AiPersona::singleton();
        return view('ai.prompts.call-analysis-system', compact('persona'))->render();
    }
    public function leadScoringSystemPrompt(): string { ... }
    public function visitProfileSystemPrompt(): string { ... }
}
```

4. Templates Blade: `resources/views/ai/prompts/*.blade.php` — strukturalnie czyste, łatwo dodać nowy bez ruszania logiki.

5. Wszystkie analyzers refactor → `app(PromptBuilder::class)->...()` zamiast hardkodów.

---

### Etap 3 — Modułów on/off (PRIORYTET 2)

Każdy moduł (Leady, Ringostat/VoIP, Cenniki, Reports, Faktury) musi być włączalny/wyłączalny w Settings. Część jest, część nie.

#### Audit do zrobienia

- Sprawdź który moduł ma `Setting::get('module_X_enabled')`
- Dodaj middleware/gate dla brakujących
- Hide UI (sidebar items, dashboard widgets) dla disabled
- Migracje warunkowe — jeśli moduł off, nie blokuj migracji ale nie twórz danych

---

### Etap 4 — Polish-pack pluginization (PRIORYTET 2)

Wszystkie integracje PL-specific muszą być opcjonalne, jako "Polish Pack" plugin do włączenia.

#### Co wyciąć z core do plugina

- **Fakturownia** integration — `config/fakturownia.php`, `App\Services\FakturowniaService`, kontroler — zostawić jako moduł `Modules\IntegrationsPL\Fakturownia`
- **Apilo** integration — `App\Services\ApiloService` → `Modules\IntegrationsPL\Apilo`
- **GUS REST API lookup** — `App\Services\GusService` (jeśli istnieje) → plugin
- **NIP validation** — helper można zostawić w core (lekki), ale UI tylko gdy plugin PL on
- **Polish regions** — `Modules\Leads\src\Services\PolishRegions` → `RegionsService` interface, default empty + `PolishRegions implements RegionsService` w pluginie
- **Pyszne.pl, Glovo, Uber, Wolt scrapers** — out of core, zostają tylko Google Maps + OpenStreetMap (uniwersalne globalnie)
- **Ringostat / Play Centrala** — VoIP PL-specific. Zostaw jako moduł włączalny (a uniwersalny VoIP — np. Twilio — to przyszłość).

#### Konsekwencja

Po wyłączeniu PL pack: brak NIP w formularzach klienta, brak GUS lookup, brak Fakturownia, brak Apilo, scraper leadów ograniczony do Google Maps. Reszta CRM działa.

---

### Etap 5 — Internationalization (PRIORYTET 3)

- `resources/lang/pl/`, `resources/lang/en/` — wszystkie stringi UI
- Wszystkie hardkody PL → klucze translate (`__('calendar.today')` zamiast `'Dziś'`)
- LocaleSwitcher w nagłówku
- Format dat (`config/app.php` `locale`), liczb, walut
- `Carbon::setLocale('pl')` / `'en'`
- Settings: domyślny język per instalacja

**Uwaga:** Jest dużo Polish text w **AI promptach** — te tłumaczy `Etap 2` (persona ma swój język).

---

### Etap 6 — Onboarding wizard (PRIORYTET 4)

Po fresh install (`php artisan migrate` na pustej bazie), pierwszy zalogowany robi setup:

1. Krok 1 — Brand (nazwa, logo upload, primary color picker, secondary color)
2. Krok 2 — Pierwszy admin (email, hasło)
3. Krok 3 — Włączone moduły (checkboxy: Leady, VoIP, Cenniki, Reports, Faktury PL, Apilo PL…)
4. Krok 4 — AI Persona (template do wypełnienia z preflight examples per branża: gastronomia, sklep e-commerce, agencja marketingowa, konsulting B2B…)
5. Krok 5 — Integracje (klucze API: Gemini/OpenAI, MailerSend, opcjonalnie PL pack)
6. Krok 6 — Done, redirect do dashboard

Implementacja: middleware `EnsureSetupComplete` przekierowuje na `/setup` jeśli `Setting::get('setup_complete')` ≠ true.

---

### Etap 7 — Multi-tenancy (PRIORYTET 5, opcjonalne)

**Skip jeśli single-tenant per deploy.**

Jeśli SaaS hostowany przez nas:
- `tenant_id` na każdym modelu z global scope
- Subdomain routing (klient1.overcrm.app, klient2.overcrm.app)
- Tenant onboarding flow (provisioning bazy/schematu)
- Billing (Stripe? FastSpring?)
- License keys / subscriptions

To duża zmiana — odrębny projekt.

---

## Checklist czyszczenia forku PRZED pierwszym commitem

W tym kopiu już jest wykluczone:
- `vendor/`, `node_modules/`, `.git/` (excluded w copy)
- `storage/app/legacy_files/` (PDFy CK)
- `storage/app/gba-roms/` (przypadkowe pliki)
- `storage/app/ai_memory/` (notatki AI specyficzne)
- `.env` (został tylko `.env.example`)

**Zostało do zrobienia w nowym chacie (przed `git init` lub jako pierwszy commit "cleanup"):**

- [ ] `127_0_0_1.sql` — lokalny dump bazy z danymi CK. **PRZECZYTAJ i USUŃ.**
- [ ] `cennik.html` — sprawdź czy ma CK content; jeśli tak, usuń lub przerób na neutralny szablon
- [ ] `planner.chickenking.co.code-workspace` — usuń lub przemianuj na `overcrm.code-workspace`
- [ ] `database/migrations/*chicken_king_price_list*` — wszystkie migracje wstawiające HTML cennika CK do `price_lists`. Usuń lub zachowaj jako "example seed" (opcjonalny)
- [ ] `database/seeders/` — przejrzyj WSZYSTKIE seedery, usuń te z CK content (chicken_king_*, panierka_*, dobra_szama_*)
- [ ] Email templates w seederach — szablony powitalne, follow-upy mogą zawierać CK branding
- [ ] `.env.example` — wyzeruj klucze API (zostaw tylko stub'y bez wartości):
  ```
  GEMINI_API_KEY=
  OPENAI_API_KEY=
  FAKTUROWNIA_API_KEY=
  FAKTUROWNIA_DOMAIN=
  APILO_CLIENT_ID=
  APILO_CLIENT_SECRET=
  APILO_INSTANCE_URL=
  MAILERSEND_API_KEY=
  RINGOSTAT_API_KEY=
  PLAY_CLIENT_ID=
  PLAY_CLIENT_SECRET=
  PLAY_PRIVATE_KEY=
  GUS_API_KEY=
  ```
- [ ] `composer.json` — `name`, `description`, `keywords`
- [ ] `package.json` — `name`
- [ ] `config/changelog.json` — wyzeruj entries (fresh start jako "1.0.0 — initial fork")
- [ ] `README.md`, `DOKUMENTACJA.md`, `INSTALACJA_VPS.md`, `AI_INSTRUKCJE.md`, `PLAN_NOWY_PLANNER.md` — przejrzyj, większość ma CK content. Możesz usunąć i napisać nowy README dla OVERCRM
- [ ] `deploy.sh` — sprawdź ścieżki, host, branche
- [ ] `scripts/` — jeśli są skrypty deployment z hardkodowanymi hostami CK, usuń
- [ ] `tests/` — pewnie jest mało testów, ale przejrzyj fixtures czy nie zawierają CK data

---

## Co NIE jest w MVP "uniwersalnego CRM"

Skip na początku — możesz dodać później jak będzie zapotrzebowanie:

- Wsparcie wielu walut (zostaw PLN; opcjonalnie EUR/USD przez Settings)
- Mobile app (PWA już jest jakoś, native app — nie)
- API publiczne (REST) — dodaj gdy klient potrzebuje
- Webhooks — j.w.
- Integracje: Salesforce, HubSpot, Zapier, Mailchimp
- White-label SaaS (wymaga multi-tenancy)
- Zaawansowane analytics (zostaw raporty marżowości jakie są w planner-v2)

---

## Sugerowany prompt dla pierwszej rozmowy z Claude w nowym projekcie

```
Mam fork CRM-a z planner-v2 (Chicken King Family — dostawca panierki dla gastronomii).
Cel: zrobić z niego uniwersalny CRM dla różnych klientów B2B (małe/średnie firmy).

PRZECZYTAJ: PLAN_PRZEBUDOWY.md w korzeniu projektu.

Zacznij od kroku "Czyszczenie forku" — wykonaj checklist (sql dump, cennik, workspace, 
migracje CK, seedery, .env.example, composer.json, package.json, changelog).
Pokazuj mi co planujesz usunąć/zmienić zanim ruszysz dane.

Po cleanup zrób initial commit "chore: clean fork from planner-v2".

Potem przejdź do Etapu 1 (Branding extraction):
1. Audyt — wylistuj wszystkie pliki z hardkodami "Chicken King", "panierka", #FFC000
2. Zaproponuj config/brand.php + UI Settings → Branding
3. Refaktor pliku po pliku, zaczynając od najbardziej widocznych (login, layout, dashboard)

Pomiń multi-tenancy i i18n — to później.
```

---

## Status ukończenia

- [x] Etap 0 — Setup (kopia z planner-v2, fresh git init, plan napisany)
- [ ] Etap 0.5 — Cleanup (checklist powyżej)
- [ ] Etap 1 — Branding extraction
- [ ] Etap 2 — AI Persona extraction
- [ ] Etap 3 — Moduły on/off audit
- [ ] Etap 4 — Polish-pack pluginization
- [ ] Etap 5 — i18n
- [ ] Etap 6 — Onboarding wizard
- [ ] Etap 7 — Multi-tenancy (opcjonalne)

---

## Notatki techniczne z planner-v2 (warte zachowania)

Stack i decyzje, które się sprawdziły — możesz na nich budować:

- **AI abstrakcja** — `App\Services\AI\AiClient` interface + Gemini/OpenAI-compat/Whisper.cpp implementations. Gemma 3 reasoning model wymagał fallbacku na `reasoning_content`. Whisper.cpp ma natywny `/inference`, nie `/v1/audio/transcriptions`. Provider wybierany przez Settings.
- **Asynchroniczna analiza** — `AnalyzeCallJob` + queue worker przez cron (`* * * * * php artisan queue:work --stop-when-empty --max-time=55`). Frontend polluje status co 4s. Eliminuje 524 timeout Cloudflare.
- **Calendar managers (m2m)** — handlowiec może być przypisany jako "opiekun" kalendarza innego usera (`calendar_managers` table). W kalendarzu dropdown przełączania widoku.
- **Reports XLSX** — `PhpSpreadsheet` z wykresami, wielo-arkuszowy. Logo + kolory marki — DO ZRAFAKTORYZOWANIA na `config('brand')`.
- **Cloudflare Tunnel** — lokalny LM Studio + Whisper.cpp wystawiony pod `llm.<domain>.com` + `whisper.<domain>.com`. Działa stabilnie.
- **Permissions system** — `permissions` + `user_permissions` (m2m), uniwersalne, zostaw jak jest.
