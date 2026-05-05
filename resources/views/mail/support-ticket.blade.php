@component('mail::message')
# Nowe zgłoszenie: {{ $ticket->subject }}

**Kategoria:** {{ $ticket->category }}
**Domena:** {{ $ticket->meta['domain'] ?? 'n/a' }}
**URL:** {{ $ticket->meta['url'] ?? 'n/a' }}
**Wersja aplikacji:** {{ $ticket->meta['app_version'] ?? 'n/a' }}
**Status licencji:** {{ $ticket->meta['license_status'] ?? 'n/a' }}

@if($ticket->user)
**Użytkownik:** {{ $ticket->user->name }} (`{{ $ticket->user->email }}`)
**Rola:** {{ $ticket->user->role }}
@else
**Użytkownik:** anonimowy
@endif

---

## Treść zgłoszenia

{{ $ticket->message }}

---

@if($logExcerpt)
Logi (`storage/logs/laravel.log`, ostatnie ~200 linii) załączono jako `laravel-log-tail.txt`.
@else
Użytkownik nie załączył logów.
@endif

@component('mail::subcopy')
Ticket #{{ $ticket->id }} · {{ $ticket->created_at->format('Y-m-d H:i') }}
@endcomponent
@endcomponent
