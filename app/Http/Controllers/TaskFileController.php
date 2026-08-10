<?php

namespace App\Http\Controllers;

use App\Contracts\StorageProvider;
use App\Models\Task;
use App\Models\TaskFile;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Załączniki zadań.
 *
 * Kontroler nie wie, gdzie fizycznie leżą pliki — pyta o aktywnego dostawcę
 * zdolności `storage`. Dzięki temu włączenie modułu Dysku Google przenosi nowe
 * załączniki do chmury bez zmiany tego kodu.
 *
 * Dostęp jest dokładnie taki sam jak do samego zadania: kto widzi zadanie,
 * ten widzi jego załączniki.
 */
class TaskFileController extends Controller
{
    /** Katalog na załączniki u dostawcy — jeden na zadanie. */
    protected function folder(Task $task): string
    {
        return 'zadania/'.$task->id;
    }

    public function store(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        $request->validate([
            // 20 MB. Powyżej tego rozmiaru załącznik do zadania przestaje być
            // załącznikiem, a zaczyna być plikiem, który powinien żyć na dysku.
            'file' => 'required|file|max:20480',
        ]);

        /** @var StorageProvider $storage */
        $storage = app(ProviderRegistry::class)->active('storage');
        $plik = $request->file('file');

        try {
            $wpis = $storage->upload(
                $plik->getRealPath(),
                $plik->getClientOriginalName(),
                $this->folder($task)
            );
        } catch (\Throwable $e) {
            Log::warning('Nie udało się zapisać załącznika zadania', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Nie udało się zapisać pliku: '.$e->getMessage());
        }

        TaskFile::create([
            'task_id' => $task->id,
            'user_id' => $request->user()?->id,
            'provider' => $storage->key(),
            'external_id' => $wpis['id'],
            'name' => $wpis['name'] ?? $plik->getClientOriginalName(),
            // Rozmiar i typ bierzemy z przesłanego pliku, a nie od dostawcy —
            // część dostawców nie zwraca ich od razu po wgraniu.
            'mime' => $plik->getClientMimeType(),
            'size' => $plik->getSize(),
            'web_url' => $wpis['web_url'] ?? null,
        ]);

        return back()->with('success', 'Załącznik dodany.');
    }

    public function download(Task $task, TaskFile $file): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view', $task);
        abort_unless($file->task_id === $task->id, 404);

        // Plik wgrany przez innego dostawcę niż aktualnie aktywny — nie mamy
        // czym go pobrać. Lepiej powiedzieć to wprost niż rzucić wyjątkiem.
        if (!$file->isReachable()) {
            return back()->with(
                'error',
                "Ten plik został wgrany przez dostawcę „{$file->provider}”, a aktywny jest inny. Przełącz dostawcę plików albo wgraj załącznik ponownie."
            );
        }

        /** @var StorageProvider $storage */
        $storage = app(ProviderRegistry::class)->active('storage');

        try {
            $sciezka = $storage->download($file->external_id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Nie udało się pobrać pliku: '.$e->getMessage());
        }

        // deleteFileAfterSend — plik jest kopią w katalogu tymczasowym.
        return response()->download($sciezka, $file->name)->deleteFileAfterSend(true);
    }

    public function destroy(Task $task, TaskFile $file): RedirectResponse
    {
        Gate::authorize('update', $task);
        abort_unless($file->task_id === $task->id, 404);

        if ($file->isReachable()) {
            try {
                app(ProviderRegistry::class)->active('storage')->trash($file->external_id);
            } catch (\Throwable $e) {
                // Nie przerywamy: wpis w bazie i tak ma zniknąć, inaczej użytkownik
                // zostaje z pozycją na liście, której nie da się usunąć.
                Log::warning('Nie udało się usunąć pliku u dostawcy', [
                    'task_file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $file->delete();

        return back()->with('success', 'Załącznik usunięty.');
    }

    /** Lista załączników — dla widoków odświeżanych bez przeładowania strony. */
    public function index(Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        return response()->json([
            'files' => $task->files()->with('user:id,name')->get()->map(fn (TaskFile $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'size' => $f->readable_size,
                'mime' => $f->mime,
                'reachable' => $f->isReachable(),
                'uploaded_by' => $f->user?->name,
                'created_at' => $f->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
