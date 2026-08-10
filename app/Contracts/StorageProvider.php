<?php

namespace App\Contracts;

/**
 * Abstrakcja repozytorium plików firmowych. Załączniki do zadań, do maili
 * i menedżer plików mają działać niezależnie od tego, gdzie klient trzyma dane.
 *
 * Providery: dysk lokalny (domyślny, zawsze dostępny), Google Drive,
 * w przyszłości OneDrive / S3.
 *
 * Kategoria jednoaktywna. Rdzeń zawsze ma provider lokalny, więc — inaczej
 * niż telefonia czy AI — ta zdolność nigdy nie jest niedostępna.
 */
interface StorageProvider
{
    public function key(): string;

    public function label(): string;

    public function isAvailable(): bool;

    /**
     * Zawartość folderu. `$folderId === null` oznacza katalog główny.
     * `$query` filtruje po nazwie (provider decyduje, czy po stronie API,
     * czy lokalnie).
     *
     * Każdy wpis: [id, name, mime, size, is_folder, modified_at, web_url, icon_url]
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $folderId = null, ?string $query = null): array;

    /** Tworzy folder i zwraca jego wpis w formacie jak w `list()`. */
    public function createFolder(string $name, ?string $parentId = null): array;

    /**
     * Wgrywa plik z lokalnej ścieżki. Zwraca wpis w formacie jak w `list()`.
     *
     * @throws \RuntimeException gdy przekroczono limit rozmiaru providera
     */
    public function upload(string $localPath, string $name, ?string $folderId = null): array;

    /**
     * Pobiera plik do katalogu tymczasowego i zwraca ścieżkę.
     * Caller odpowiada za sprzątnięcie pliku.
     */
    public function download(string $fileId): string;

    public function rename(string $fileId, string $name): bool;

    /** Przenosi do kosza (nie kasuje trwale — odzyskiwalność jest po stronie providera). */
    public function trash(string $fileId): bool;

    /**
     * Link do udostępnienia pliku na zewnątrz (np. do wklejenia w mailu).
     * Zwraca null, gdy provider nie potrafi udostępniać publicznie —
     * wtedy caller musi załączyć plik zamiast linkować.
     */
    public function shareLink(string $fileId): ?string;
}
