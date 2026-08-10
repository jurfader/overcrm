<?php

namespace App\Contracts;

/**
 * Abstrakcja modelu językowego. Rdzeń i moduły funkcjonalne nie wiedzą,
 * czy pod spodem jest chmura, czy model uruchomiony u klienta na serwerze.
 *
 * Providery: Gemini, OpenAI-compatible (OpenAI / OpenRouter / LM Studio /
 * Ollama — wszystko, co wystawia /v1/chat/completions), Whisper.cpp (tylko audio).
 *
 * Kategoria jednoaktywna, ale z osobnym wyborem dla audio: modele
 * open-source uruchamiane lokalnie zwykle nie mają transkrypcji, więc
 * instalacja może mieć tekst na modelu lokalnym, a audio na chmurowym.
 * Rozstrzyga o tym ProviderRegistry przez kategorię 'ai_audio'.
 */
interface AiProvider
{
    public function key(): string;

    public function label(): string;

    /** Czy provider ma komplet konfiguracji (klucz/adres) i da się go użyć. */
    public function isAvailable(): bool;

    /** Nazwa aktualnie ustawionego modelu — do pokazania w UI i w logach. */
    public function model(): string;

    /**
     * Pojedyncze zapytanie tekstowe.
     *
     * @throws \App\Exceptions\AiException gdy provider niedostępny lub API odrzuciło
     */
    public function chat(string $prompt, ?string $system = null): string;

    /**
     * Zapytanie wymuszające odpowiedź w JSON. Provider odpowiada za wymuszenie
     * formatu (response_mime_type / response_format) ORAZ za obronę przed
     * modelem, który mimo to opakuje JSON w blok markdown.
     *
     * @return array<mixed> zdekodowana odpowiedź
     * @throws \App\Exceptions\AiException gdy odpowiedzi nie da się sparsować
     */
    public function chatJson(string $prompt, ?string $system = null): array;

    /** Czy provider potrafi transkrybować audio. */
    public function supportsAudio(): bool;

    /**
     * Transkrypcja pliku audio. Provider odpowiada za podział długich nagrań
     * na fragmenty, jeśli jego API ma limit długości.
     *
     * @throws \App\Exceptions\AiException
     */
    public function transcribe(string $audioPath, ?string $language = null): string;
}
