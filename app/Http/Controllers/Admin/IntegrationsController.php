<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IntegrationsController extends Controller
{
    public function __construct(protected ProviderRegistry $registry) {}

    /**
     * Zapis wyboru dostawcy per kategoria zdolności.
     *
     * Obsługuje wszystkie kategorie, także te bez dostawcy rdzenia (telefonia, AI).
     * Kategorie wieloaktywne ('notification') przychodzą jako lista włączonych
     * kanałów, jednoaktywne jako pojedynczy klucz.
     *
     * Walidacja jest luźna z rozmysłem: kategorie zależą od tego, jakie moduły
     * klient ma zainstalowane, więc sztywna lista pól w validate() rozjeżdżałaby
     * się przy każdym nowym module. Zamiast tego setActive()/setEnabled()
     * odrzucają nieznane klucze same.
     */
    public function update(Request $request): RedirectResponse
    {
        $errors = [];

        foreach ($this->registry->categories() as $category) {
            $single = $request->input('provider_'.$category);
            $multi = $request->input('providers_'.$category);

            try {
                if (in_array($category, ProviderRegistry::MULTI, true)) {
                    if (is_array($multi)) {
                        $this->registry->setEnabled($category, $multi);
                    }

                    continue;
                }

                if (is_string($single) && $single !== '') {
                    $this->registry->setActive($category, $single);
                }
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors) {
            return back()->with('error', implode(' ', $errors));
        }

        return back()->with('success', 'Zapisano wybór dostawców');
    }
}
