<?php

namespace App\Http\Controllers;

use App\Mail\SupportTicketMail;
use App\Models\Setting;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SupportController extends Controller
{
    public function submit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject'    => 'required|string|max:200',
            'category'   => 'required|in:bug,question,feature,other',
            'message'    => 'required|string|max:5000',
            'attach_log' => 'nullable|boolean',
        ]);

        $logExcerpt = ($data['attach_log'] ?? true) ? $this->tailLog(200) : null;

        $ticket = SupportTicket::create([
            'user_id'    => $request->user()?->id,
            'subject'    => $data['subject'],
            'category'   => $data['category'],
            'message'    => $data['message'],
            'attach_log' => (bool)($data['attach_log'] ?? true),
            'status'     => 'new',
            'meta'       => [
                'url'             => $request->header('referer'),
                'user_agent'      => $request->userAgent(),
                'domain'          => parse_url(config('app.url'), PHP_URL_HOST),
                'app_version'     => $this->buildVersion(),
                'license_status'  => Setting::get('license_status', 'unknown'),
                'license_plan'    => Setting::get('license_plan', null),
            ],
        ]);

        try {
            Mail::to(config('services.support.email', 'support@overmedia.pl'))
                ->send(new SupportTicketMail($ticket, $logExcerpt));

            $ticket->update(['status' => 'sent', 'sent_at' => now()]);

            return back()->with('success', 'Zgłoszenie wysłane. Odpowiemy najszybciej jak to możliwe.');
        } catch (\Throwable $e) {
            Log::warning('Support ticket mail failed', ['ticket' => $ticket->id, 'error' => $e->getMessage()]);
            $ticket->update(['status' => 'failed', 'email_error' => $e->getMessage()]);

            return back()->with('error', 'Zgłoszenie zapisane, ale e-mail się nie wysłał. Support otrzyma je przy najbliższej okazji.');
        }
    }

    /**
     * Pobiera ostatnie N linii z storage/logs/laravel.log (najświeższe na dole).
     * Tail przez `seek` od końca pliku, żeby nie ładować całego (loga mogą być duże).
     */
    protected function tailLog(int $lines = 200): ?string
    {
        $path = storage_path('logs/laravel.log');
        if (!is_readable($path)) return null;

        $size = @filesize($path);
        if (!$size) return null;

        $chunk = 4096;
        $bytesToRead = min($size, $chunk * (int) ceil($lines / 20)); // heurystyka — ~20 linii na 4KB

        $handle = @fopen($path, 'rb');
        if (!$handle) return null;

        try {
            fseek($handle, -$bytesToRead, SEEK_END);
            $buffer = fread($handle, $bytesToRead) ?: '';
        } finally {
            fclose($handle);
        }

        $rows = preg_split("/\r?\n/", $buffer);
        $tail = array_slice($rows, -$lines);
        return implode(PHP_EOL, $tail);
    }

    protected function buildVersion(): string
    {
        $manifestPath = public_path('build/manifest.json');
        if (!file_exists($manifestPath)) return 'dev';
        try {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            return $manifest['resources/js/app.js']['file'] ?? (string) filemtime($manifestPath);
        } catch (\Throwable $e) {
            return 'dev';
        }
    }
}
