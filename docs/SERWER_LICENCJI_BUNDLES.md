# Pole `bundles` — co musi dorobić serwer licencji

Rdzeń OVERCRM jest gotowy na pakiety licencyjne. Brakuje **wyłącznie** strony
serwerowej: `/activate` i `/validate` nie zwracają jeszcze pola `bundles`.

Dopóki go nie zwracają, `LicenseService::bundles()` oddaje pustą listę i
`hasBundle()` przepuszcza tylko `overcrm-core`. Praktyczny skutek: **klient,
który kupi Pakiet AI, nie dostanie ani jednego modułu z tego pakietu.**
To blokuje sprzedaż całej Fazy 4.

## Kontrakt

Do odpowiedzi `/activate` i `/validate` dochodzi jedno pole — tablica ciągów:

```json
{
  "success": true,
  "plan": "pro",
  "expiresAt": "2027-08-10T00:00:00.000Z",
  "bindingToken": "…",
  "bundles": ["overcrm-ai", "overcrm-telefonia"],
  "signature": "…"
}
```

Klient używa `valid` zamiast `success` w odpowiedzi `/validate` — reszta bez zmian.

### Dozwolone wartości

| Identyfikator | Etykieta w marketplace |
|---|---|
| `overcrm-core` | W licencji podstawowej |
| `overcrm-ai` | Pakiet AI |
| `overcrm-komunikacja` | Pakiet Komunikacja |
| `overcrm-telefonia` | Pakiet Telefonia |
| `overcrm-analityka` | Pakiet Analityka |
| `overcrm-sprzedaz` | Pakiet Sprzedaż |
| `overcrm-pliki` | Pakiet Pliki |
| `overcrm-wdrozenie` | Pakiet Wdrożenie |

Źródłem prawdy jest `MarketplaceService::BUNDLE_LABELS`. Ciąg spoza tej listy
nie wywoła błędu — moduł po prostu nigdy się nie odblokuje. Pilnuje tego
`ModuleHygieneTest::test_bundle_z_manifestu_jest_znanym_pakietem`.

**`overcrm-core` nie musi być wysyłany.** `hasBundle()` traktuje go jako wliczony
w licencję i wymaga jedynie, by licencja była ważna. Wysłanie go nie szkodzi.

## NAJWAŻNIEJSZE: podpis

`bundles` **wchodzi do podpisywanego payloadu i musi być OSTATNIM kluczem.**

Klient buduje wiadomość do weryfikacji tak (`verifyResponseSignature`):

```
/activate   →  JSON.stringify({ success, plan, expiresAt, bindingToken, bundles })
/validate   →  JSON.stringify({ valid,   plan, expiresAt, bindingToken, bundles })
```

Zasady, których nie wolno naruszyć:

1. **Kolejność kluczy jest częścią podpisu.** Klient odtwarza payload klucz po
   kluczu w tej kolejności i porównuje bajty. Inna kolejność = nieprawidłowy
   podpis = `INVALID_SIGNATURE` i zablokowany CRM.
2. **`bundles` doklejane jest na koniec tylko wtedy, gdy pole w ogóle występuje
   w odpowiedzi.** Dzięki temu starszy serwer, który go nie zna, nadal przechodzi
   weryfikację. Innymi słowy: albo wysyłasz `bundles` i podpisujesz je jako
   ostatni klucz, albo nie wysyłasz wcale. **Nie ma stanu pośredniego** —
   wysłanie `bundles` bez uwzględnienia go w podpisie wywali weryfikację.
3. Brakujące pola idą do payloadu jako `null`, nie są pomijane.
4. Po stronie PHP payload powstaje przez
   `json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`.
   Dla identyfikatorów ASCII odpowiada to `JSON.stringify` w Node bez zastrzeżeń.

To nie jest nadgorliwość: doklejenie `bundles` do podpisu jest właśnie tą
obroną, o którą chodzi. Bez tego wystarczyłoby dopisać sobie pakiet w odpowiedzi
po drodze, żeby odblokować płatne moduły.

## Czego NIE trzeba ruszać po stronie klienta

Wszystko poniżej już działa i jest pokryte testami (`LicenseBundlesTest`):

- zapis do `settings.license_bundles` (tylko gdy serwer przysłał pole —
  starszemu serwerowi nie wolno wyzerować już nadanych uprawnień),
- osobny zamek HMAC `license_bundles_hmac`, związany z kluczem licencji
  i `installation_id`; dopisanie sobie pakietu wprost w bazie unieważnia listę,
  a skopiowanie wpisów na inną instalację nie zadziała,
- zerowanie uprawnień przy nieważnej licencji — `bundles()` zwraca pustą listę,
  gdy `isValid()` jest fałszem, więc wygasła licencja nie daje dalej dostępu
  do płatnych pakietów,
- etykiety i flaga `bundle_owned` w `/admin/marketplace`.

## Jak sprawdzić, że zadziałało

1. Nadaj licencji testowej pakiet, np. `overcrm-telefonia`.
2. W CRM-ie: `php artisan license:validate` (albo zapis klucza w `/license`).
3. `settings.license_bundles` ma zawierać `["overcrm-telefonia"]`,
   a `license_bundles_hmac` — niepustą wartość.
4. `/admin/marketplace`: PlayCentrala i Ringostat mają dostać `bundle_owned: true`,
   moduły z innych pakietów — `false`.
5. Kontrola negatywna: zmień ręcznie `settings.license_bundles`, dopisując
   `overcrm-ai`. Marketplace **musi** przestać pokazywać jakiekolwiek pakiety
   jako posiadane — rozjechany HMAC zeruje całą listę. Jeśli AI się odblokuje,
   zamek nie działa.
