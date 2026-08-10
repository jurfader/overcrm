<?php

namespace App\Support\Providers;

use App\Contracts\StorageProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Domyślny provider plików — dysk lokalny instalacji (storage/app/files).
 * Zawsze dostępny, więc zdolność 'storage' nigdy nie jest pusta i moduły
 * mogą na niej polegać bezwarunkowo.
 *
 * Zastępowany przez GoogleDriveStorageProvider, gdy klient włączy moduł Pliki.
 */
class LocalStorageProvider implements StorageProvider
{
    protected const ROOT = 'files';

    public function key(): string
    {
        return 'local';
    }

    public function label(): string
    {
        return 'Dysk lokalny';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function list(?string $folderId = null, ?string $query = null): array
    {
        $disk = Storage::disk('local');
        $path = $this->path($folderId);

        if (!$disk->exists($path)) {
            return [];
        }

        $out = [];

        foreach ($disk->directories($path) as $dir) {
            $out[] = $this->entry($dir, true);
        }

        foreach ($disk->files($path) as $file) {
            $out[] = $this->entry($file, false);
        }

        if ($query) {
            $out = array_values(array_filter(
                $out,
                fn ($e) => Str::contains(Str::lower($e['name']), Str::lower($query))
            ));
        }

        return $out;
    }

    public function createFolder(string $name, ?string $parentId = null): array
    {
        $path = $this->path($parentId).'/'.$this->safeName($name);

        Storage::disk('local')->makeDirectory($path);

        return $this->entry($path, true);
    }

    public function upload(string $localPath, string $name, ?string $folderId = null): array
    {
        $path = $this->path($folderId).'/'.$this->safeName($name);

        Storage::disk('local')->put($path, file_get_contents($localPath));

        return $this->entry($path, false);
    }

    public function download(string $fileId): string
    {
        $path = $this->path($fileId);
        $disk = Storage::disk('local');

        if (!$disk->exists($path)) {
            throw new \RuntimeException("Plik '{$fileId}' nie istnieje.");
        }

        $tmp = sys_get_temp_dir().'/ovc_'.Str::random(12).'_'.basename($path);
        file_put_contents($tmp, $disk->get($path));

        return $tmp;
    }

    public function rename(string $fileId, string $name): bool
    {
        $path = $this->path($fileId);
        $target = dirname($path).'/'.$this->safeName($name);

        return Storage::disk('local')->move($path, $target);
    }

    public function trash(string $fileId): bool
    {
        // Dysk lokalny nie ma kosza — przenosimy do .trash zamiast kasować,
        // żeby zachować semantykę kontraktu (operacja odwracalna).
        $path = $this->path($fileId);
        $disk = Storage::disk('local');

        if (!$disk->exists($path)) {
            return false;
        }

        $target = self::ROOT.'/.trash/'.now()->format('Ymd_His').'_'.basename($path);
        $disk->makeDirectory(self::ROOT.'/.trash');

        return $disk->move($path, $target);
    }

    public function shareLink(string $fileId): ?string
    {
        // Dysk lokalny nie udostępnia publicznie — caller ma załączyć plik.
        return null;
    }

    /** Identyfikatorem jest ścieżka względna wobec katalogu plików. */
    protected function path(?string $id): string
    {
        if ($id === null || $id === '') {
            return self::ROOT;
        }

        // Twarda blokada wyjścia poza katalog plików (../../.env).
        $clean = str_replace('..', '', $id);
        $clean = ltrim($clean, '/');

        return Str::startsWith($clean, self::ROOT) ? $clean : self::ROOT.'/'.$clean;
    }

    protected function safeName(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $name) ?: 'plik';
    }

    protected function entry(string $path, bool $isFolder): array
    {
        $disk = Storage::disk('local');

        return [
            'id'          => $path,
            'name'        => basename($path),
            'mime'        => $isFolder ? 'application/vnd.folder' : $disk->mimeType($path),
            'size'        => $isFolder ? null : $disk->size($path),
            'is_folder'   => $isFolder,
            'modified_at' => $disk->lastModified($path),
            'web_url'     => null,
            'icon_url'    => null,
        ];
    }
}
