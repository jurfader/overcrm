<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Developer emails (ukryta rola z uprawnieniami admina)
    |--------------------------------------------------------------------------
    |
    | Adresy email użytkowników z pełnymi uprawnieniami developerskimi.
    | Oddzielone przecinkami. Użytkownicy z tych adresów mają dostęp
    | jak administrator, niezależnie od roli w bazie.
    |
    */
    'emails' => array_filter(array_map('trim', explode(',', env('DEVELOPER_EMAILS', '')))),

];
