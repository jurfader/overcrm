<?php

namespace Modules\Leads\Services;

class PolishRegions
{
    public static function all(): array
    {
        return [
            'dolnoslaskie' => ['name' => 'Dolnośląskie', 'cities' => ['Wrocław', 'Wałbrzych', 'Legnica', 'Jelenia Góra', 'Lubin', 'Głogów', 'Świdnica', 'Oleśnica', 'Bolesławiec', 'Oława', 'Kłodzko', 'Dzierżoniów', 'Zgorzelec', 'Bielawa', 'Strzegom', 'Jawor', 'Kamienna Góra', 'Ząbkowice Śląskie', 'Polkowice', 'Środa Śląska']],
            'kujawsko-pomorskie' => ['name' => 'Kujawsko-pomorskie', 'cities' => ['Bydgoszcz', 'Toruń', 'Włocławek', 'Grudziądz', 'Inowrocław', 'Brodnica', 'Świecie', 'Chełmno', 'Nakło nad Notecią', 'Mogilno', 'Żnin', 'Rypin', 'Ciechocinek', 'Tuchola', 'Solec Kujawski', 'Koronowo', 'Aleksandrów Kujawski']],
            'lubelskie' => ['name' => 'Lubelskie', 'cities' => ['Lublin', 'Zamość', 'Chełm', 'Biała Podlaska', 'Puławy', 'Świdnik', 'Łuków', 'Kraśnik', 'Lubartów', 'Tomaszów Lubelski', 'Hrubieszów', 'Łęczna', 'Radzyń Podlaski', 'Biłgoraj', 'Janów Lubelski', 'Opole Lubelskie']],
            'lubuskie' => ['name' => 'Lubuskie', 'cities' => ['Zielona Góra', 'Gorzów Wielkopolski', 'Nowa Sól', 'Żary', 'Żagań', 'Świebodzin', 'Międzyrzecz', 'Kostrzyn nad Odrą', 'Gubin', 'Sulęcin', 'Wschowa', 'Szprotawa', 'Krosno Odrzańskie']],
            'lodzkie' => ['name' => 'Łódzkie', 'cities' => ['Łódź', 'Piotrków Trybunalski', 'Pabianice', 'Tomaszów Mazowiecki', 'Bełchatów', 'Zgierz', 'Skierniewice', 'Radomsko', 'Kutno', 'Sieradz', 'Zduńska Wola', 'Łowicz', 'Ozorków', 'Wieluń', 'Opoczno', 'Łask', 'Rawa Mazowiecka']],
            'malopolskie' => ['name' => 'Małopolskie', 'cities' => ['Kraków', 'Tarnów', 'Nowy Sącz', 'Oświęcim', 'Chrzanów', 'Nowy Targ', 'Olkusz', 'Gorlice', 'Bochnia', 'Miechów', 'Zakopane', 'Wieliczka', 'Limanowa', 'Brzesko', 'Myślenice', 'Wadowice', 'Trzebinia', 'Andrychów', 'Kęty']],
            'mazowieckie' => ['name' => 'Mazowieckie', 'cities' => ['Warszawa', 'Radom', 'Płock', 'Siedlce', 'Pruszków', 'Legionowo', 'Piaseczno', 'Otwock', 'Wołomin', 'Ciechanów', 'Mińsk Mazowiecki', 'Żyrardów', 'Grodzisk Mazowiecki', 'Sochaczew', 'Ostrołęka', 'Nowy Dwór Mazowiecki', 'Marki', 'Ząbki', 'Kobyłka', 'Józefów', 'Piastów', 'Łomianki']],
            'opolskie' => ['name' => 'Opolskie', 'cities' => ['Opole', 'Kędzierzyn-Koźle', 'Nysa', 'Brzeg', 'Kluczbork', 'Prudnik', 'Strzelce Opolskie', 'Krapkowice', 'Namysłów', 'Głubczyce', 'Zdzieszowice']],
            'podkarpackie' => ['name' => 'Podkarpackie', 'cities' => ['Rzeszów', 'Przemyśl', 'Stalowa Wola', 'Mielec', 'Tarnobrzeg', 'Krosno', 'Dębica', 'Sanok', 'Jarosław', 'Jasło', 'Łańcut', 'Nisko', 'Leżajsk', 'Ropczyce', 'Przeworsk', 'Ustrzyki Dolne']],
            'podlaskie' => ['name' => 'Podlaskie', 'cities' => ['Białystok', 'Suwałki', 'Łomża', 'Augustów', 'Bielsk Podlaski', 'Hajnówka', 'Zambrów', 'Grajewo', 'Sokółka', 'Siemiatycze', 'Mońki', 'Kolno']],
            'pomorskie' => ['name' => 'Pomorskie', 'cities' => ['Gdańsk', 'Gdynia', 'Sopot', 'Słupsk', 'Tczew', 'Rumia', 'Starogard Gdański', 'Wejherowo', 'Reda', 'Chojnice', 'Kwidzyn', 'Malbork', 'Bytów', 'Pruszcz Gdański', 'Lębork', 'Kartuzy', 'Kościerzyna']],
            'slaskie' => ['name' => 'Śląskie', 'cities' => ['Katowice', 'Częstochowa', 'Sosnowiec', 'Gliwice', 'Zabrze', 'Bytom', 'Bielsko-Biała', 'Ruda Śląska', 'Rybnik', 'Tychy', 'Dąbrowa Górnicza', 'Chorzów', 'Jaworzno', 'Mysłowice', 'Siemianowice Śląskie', 'Tarnowskie Góry', 'Będzin', 'Żory', 'Mikołów', 'Cieszyn', 'Racibórz', 'Wodzisław Śląski', 'Żywiec', 'Myszków', 'Lubliniec']],
            'swietokrzyskie' => ['name' => 'Świętokrzyskie', 'cities' => ['Kielce', 'Ostrowiec Świętokrzyski', 'Starachowice', 'Skarżysko-Kamienna', 'Sandomierz', 'Końskie', 'Busko-Zdrój', 'Jędrzejów', 'Staszów', 'Pińczów', 'Włoszczowa']],
            'warminsko-mazurskie' => ['name' => 'Warmińsko-mazurskie', 'cities' => ['Olsztyn', 'Elbląg', 'Ełk', 'Ostróda', 'Iława', 'Giżycko', 'Kętrzyn', 'Szczytno', 'Mrągowo', 'Bartoszyce', 'Działdowo', 'Lidzbark Warmiński', 'Pisz', 'Nidzica']],
            'wielkopolskie' => ['name' => 'Wielkopolskie', 'cities' => ['Poznań', 'Kalisz', 'Konin', 'Piła', 'Ostrów Wielkopolski', 'Gniezno', 'Leszno', 'Luboń', 'Swarzędz', 'Śrem', 'Turek', 'Rawicz', 'Jarocin', 'Wągrowiec', 'Środa Wielkopolska', 'Krotoszyn', 'Pleszew', 'Gostyń', 'Września', 'Koło', 'Kościan']],
            'zachodniopomorskie' => ['name' => 'Zachodniopomorskie', 'cities' => ['Szczecin', 'Koszalin', 'Stargard', 'Kołobrzeg', 'Świnoujście', 'Szczecinek', 'Wałcz', 'Białogard', 'Police', 'Goleniów', 'Gryfino', 'Gryfice', 'Świdwin', 'Drawsko Pomorskie', 'Nowogard']],
        ];
    }

    public static function getCities(string $voivodeshipKey): array
    {
        return self::all()[$voivodeshipKey]['cities'] ?? [];
    }

    public static function allCitiesFlat(): array
    {
        $cities = [];
        foreach (self::all() as $key => $region) {
            foreach ($region['cities'] as $city) {
                $cities[] = ['city' => $city, 'voivodeship' => $region['name'], 'voivodeship_key' => $key];
            }
        }
        return $cities;
    }
}
