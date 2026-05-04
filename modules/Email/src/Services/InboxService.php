<?php

namespace Modules\Email\Services;

use App\Models\UserMailConfig;
use Illuminate\Support\Facades\Cache;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

class InboxService
{
    protected int $cacheTtlMinutes = 3;

    /**
     * Pobierz host IMAP z konfiguracji (domyślnie z SMTP: smtp.gmail.com → imap.gmail.com)
     */
    public function getImapHost(UserMailConfig $config): string
    {
        if ($config->imap_host) {
            return $config->imap_host;
        }
        $host = $config->mail_host;
        if (str_contains(strtolower($host), 'smtp.gmail.com')) {
            return 'imap.gmail.com';
        }
        if (str_contains(strtolower($host), 'smtp.office365.com') || str_contains(strtolower($host), 'smtp-mail.outlook')) {
            return 'outlook.office365.com';
        }
        if (str_contains(strtolower($host), 'smtp.')) {
            return str_replace('smtp.', 'imap.', $host);
        }
        return $host;
    }

    /**
     * Pobierz port IMAP (domyślnie 993 dla SSL)
     */
    public function getImapPort(UserMailConfig $config): int
    {
        return $config->imap_port ?? 993;
    }

    /**
     * Pobierz szyfrowanie IMAP (domyślnie ssl)
     */
    public function getImapEncryption(UserMailConfig $config): ?string
    {
        return $config->imap_encryption ?? 'ssl';
    }

    /**
     * Utwórz konfigurację dla webklex/php-imap
     */
    protected function buildImapConfig(UserMailConfig $config): array
    {
        $encryption = $this->getImapEncryption($config);
        $port = $this->getImapPort($config);

        return [
            'host' => $this->getImapHost($config),
            'port' => $port,
            'protocol' => 'imap',
            'encryption' => $encryption ?: false,
            'validate_cert' => false,
            'username' => $config->mail_username,
            'password' => $config->getDecryptedPassword(),
        ];
    }

    /**
     * Dekoduj nagłówek MIME (=?UTF-8?B?...?=)
     */
    protected function decodeMimeHeader(?string $value): string
    {
        if (!$value || !is_string($value)) {
            return '';
        }
        if (!str_contains($value, '=?')) {
            return $value;
        }
        if (function_exists('imap_mime_header_decode')) {
            $decoded = @imap_mime_header_decode($value);
            if (is_array($decoded)) {
                $result = '';
                foreach ($decoded as $part) {
                    $result .= $part->text ?? '';
                }
                return $result ?: $value;
            }
        }
        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            return $decoded !== false ? $decoded : $value;
        }
        return $value;
    }

    /**
     * Pobierz listę wiadomości z skrzynki INBOX (od najnowszych, bez treści – szybsze ładowanie)
     * Wynik cachowany na 3 minuty.
     *
     * @return array{emails: array, total: int, error?: string}
     */
    public function fetchInbox(UserMailConfig $config, int $limit = 30, int $page = 1, bool $forceRefresh = false): array
    {
        $cacheKey = 'inbox:user:' . $config->user_id . ':config:' . $config->id . ':limit:' . $limit . ':page:' . $page;

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $accountConfig = $this->buildImapConfig($config);
            $cm = new ClientManager([
                'default' => 'default',
                'accounts' => ['default' => $accountConfig],
                'options' => [
                    'fetch_order' => 'desc',
                    'fetch_body' => false,
                ],
                'decoding' => [
                    'options' => [
                        'header' => 'mimeheader',
                        'message' => 'mimeheader',
                    ],
                ],
            ]);
            $client = $cm->account('default');
            $client->connect();

            $folder = $client->getFolder('INBOX');
            $messages = $folder->messages()
                ->setFetchOrder('desc')
                ->all()
                ->limit($limit, $page)
                ->get();

            $emails = [];
            foreach ($messages as $message) {
                $from = $message->getFrom()->first();
                $subject = $message->getSubject();
                $fromName = $from?->personal ?? '';
                $emails[] = [
                    'uid' => $message->getUid(),
                    'subject' => $this->decodeMimeHeader(is_string($subject) ? $subject : (string) $subject),
                    'from' => $from?->mail ?? '',
                    'from_name' => $this->decodeMimeHeader($fromName),
                    'date' => $message->getDate()?->toDate()?->format('Y-m-d H:i:s'),
                    'has_attachments' => $message->getAttachments()->count() > 0,
                    'is_seen' => $message->getFlags()->get('seen') ?? false,
                ];
            }
            usort($emails, fn ($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

            $client->disconnect();

            $result = [
                'emails' => $emails,
                'total' => count($emails),
            ];
            Cache::put($cacheKey, $result, now()->addMinutes($this->cacheTtlMinutes));

            return $result;
        } catch (ConnectionFailedException $e) {
            return [
                'emails' => [],
                'total' => 0,
                'error' => $this->friendlyImapError($e->getMessage(), $config),
            ];
        } catch (\Throwable $e) {
            return [
                'emails' => [],
                'total' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Liczba nieprzeczytanych wiadomości w INBOX (cachowane 3 min)
     */
    public function getUnreadCount(UserMailConfig $config): int
    {
        $cacheKey = 'inbox_unread:user:' . $config->user_id . ':config:' . $config->id;

        return (int) Cache::remember($cacheKey, now()->addMinutes($this->cacheTtlMinutes), function () use ($config) {
            try {
                $accountConfig = $this->buildImapConfig($config);
                $cm = new ClientManager([
                    'default' => 'default',
                    'accounts' => ['default' => $accountConfig],
                    'options' => ['fetch_body' => false],
                ]);
                $client = $cm->account('default');
                $client->connect();
                $folder = $client->getFolder('INBOX');
                $count = $folder->messages()->unseen()->count();
                $client->disconnect();

                return $count;
            } catch (\Throwable $e) {
                return 0;
            }
        });
    }

    /**
     * Wyczyść cache unread dla konfiguracji (np. po odświeżeniu)
     */
    public function clearUnreadCache(UserMailConfig $config): void
    {
        Cache::forget('inbox_unread:user:' . $config->user_id . ':config:' . $config->id);
    }

    /**
     * Sanityzacja HTML emaila do wyświetlenia w przeglądarce (v-html).
     * Usuwa wektory XSS: <script>, <iframe>, <object>, <embed>, <meta>, eventy on*=, javascript:/data: w href/src.
     * Podmienia cid: (inline images) na placeholder bo przeglądarka nie obsługuje tego schematu URL.
     */
    protected function sanitizeEmailHtmlForDisplay(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#<script\b[^>]*/?>#i', '', $html) ?? $html;
        $html = preg_replace('#<(iframe|object|embed|meta|link|base|form)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<(iframe|object|embed|meta|link|base|form)\b[^>]*/?>#i', '', $html) ?? $html;
        $html = preg_replace('#\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        $html = preg_replace('#\s(href|src|xlink:href|action|formaction)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', ' $1="#"', $html) ?? $html;
        $html = preg_replace('#\s(href|src|xlink:href)\s*=\s*(["\'])\s*vbscript:[^"\']*\2#i', ' $1="#"', $html) ?? $html;
        $html = preg_replace('#\ssrcdoc\s*=\s*(["\'])[^"\']*\1#i', '', $html) ?? $html;

        $placeholder = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        $html = preg_replace('/src=["\']cid:[^"\']*["\']/i', 'src="' . $placeholder . '"', $html);
        $html = preg_replace('/url\(["\']?cid:[^"\')\s]*["\']?\)/i', 'url("' . $placeholder . '")', $html);

        return $html;
    }

    /**
     * Pobierz treść pojedynczej wiadomości
     */
    public function fetchMessage(UserMailConfig $config, string $folderName, int $uid): ?array
    {
        try {
            $cm = new ClientManager();
            $client = $cm->make($this->buildImapConfig($config));
            $client->connect();

            $folder = $client->getFolder($folderName ?: 'INBOX');
            $message = $folder->query()->getMessageByUid((int) $uid);

            if (!$message) {
                $client->disconnect();
                return null;
            }

            $html = $message->getHTMLBody();
            $text = $message->getTextBody();
            if (!$html && $text) {
                $html = '<pre>' . htmlspecialchars($text) . '</pre>';
            }
            $html = $this->sanitizeEmailHtmlForDisplay($html ?? '');

            $from = $message->getFrom()->first();
            $toAddrs = $message->getTo();
            $toStr = is_iterable($toAddrs) ? implode(', ', array_map(fn ($a) => is_object($a) && method_exists($a, 'getMail') ? $a->getMail() : (string) $a, iterator_to_array($toAddrs))) : '';

            $subject = $message->getSubject();
            $fromName = $from?->personal ?? '';

            $result = [
                'uid' => $message->getUid(),
                'subject' => $this->decodeMimeHeader(is_string($subject) ? $subject : (string) $subject),
                'from' => $from?->mail ?? '',
                'from_name' => $this->decodeMimeHeader($fromName),
                'to' => $toStr,
                'date' => $message->getDate()?->toDate()?->format('Y-m-d H:i:s'),
                'html' => $html ?: '<p>Brak treści</p>',
                'attachments' => $message->getAttachments()->map(fn ($a) => [
                    'name' => $a->getName(),
                    'size' => $a->getSize(),
                ])->toArray(),
            ];

            $client->disconnect();

            return $result;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function friendlyImapError(string $error, UserMailConfig $config): string
    {
        $lower = strtolower($error);
        if (str_contains($lower, 'authentication') || str_contains($lower, 'login')) {
            if (str_contains(strtolower($config->mail_host), 'gmail')) {
                return 'Błąd logowania IMAP. Upewnij się, że używasz "Hasła aplikacji" (App Password) dla Gmail.';
            }
            return 'Błąd uwierzytelniania IMAP. Sprawdź login i hasło.';
        }
        if (str_contains($lower, 'connection') || str_contains($lower, 'connect')) {
            return 'Nie można połączyć się z serwerem IMAP. Sprawdź host i port (np. imap.gmail.com:993).';
        }
        return $error;
    }
}
