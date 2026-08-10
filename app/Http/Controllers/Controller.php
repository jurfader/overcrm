<?php

namespace App\Http\Controllers;

abstract class Controller
{
    // UWAGA: nie dodawać tu AuthorizesRequests.
    //
    // Trait wnosi metodę authorize($ability, $arguments), a moduł Apilo ma
    // własną akcję OAuth o tej samej nazwie i innej sygnaturze
    // (ApiloController::authorize(Request)). Dołączenie traita do klasy bazowej
    // wywraca cały moduł fatal errorem przy ładowaniu klasy — czyli rdzeń psuje
    // moduł, o którym nic nie wie.
    //
    // Do sprawdzania polityk używamy fasady Gate::authorize() bezpośrednio
    // w kontrolerze. Robi dokładnie to samo i nie zajmuje nazwy metody.
}
