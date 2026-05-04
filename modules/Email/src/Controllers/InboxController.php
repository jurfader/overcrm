<?php

namespace Modules\Email\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UserMailConfig;
use App\Services\UserMailService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Email\Services\InboxService;

class InboxController extends Controller
{
    public function __construct(
        protected InboxService $inboxService,
        protected UserMailService $mailService
    ) {}

    /**
     * Lista skrzynek – wybór konfiguracji i podgląd inbox
     */
    public function index(Request $request)
    {
        $configs = auth()->user()->mailConfigs()->verified()->orderBy('is_default', 'desc')->get();
        $configId = $request->get('config_id', $configs->first()?->id);
        $config = $configId ? $configs->find($configId) : null;

        $emails = ['emails' => [], 'total' => 0];
        $unreadCount = 0;
        $error = null;

        if ($config) {
            $forceRefresh = $request->boolean('refresh');
            if ($forceRefresh) {
                $this->inboxService->clearUnreadCache($config);
            }
            $result = $this->inboxService->fetchInbox(
                $config,
                (int) $request->get('limit', 30),
                (int) $request->get('page', 1),
                $forceRefresh
            );
            $emails = $result;
            $error = $result['error'] ?? null;
            // Pobierz unread count (zapełnia cache dla badge w menu)
            $unreadCount = $this->inboxService->getUnreadCount($config);
        }

        return Inertia::render('Email/Inbox/Index', [
            'mailConfigs' => $configs,
            'selectedConfigId' => $configId,
            'emails' => $emails['emails'],
            'total' => $emails['total'],
            'unreadCount' => $unreadCount,
            'error' => $error,
        ]);
    }

    /**
     * Pobierz treść wiadomości (AJAX)
     */
    public function show(Request $request, int $configId, int $uid)
    {
        $config = auth()->user()->mailConfigs()->verified()->findOrFail($configId);
        $message = $this->inboxService->fetchMessage(
            $config,
            $request->get('folder', 'INBOX'),
            $uid
        );

        if (!$message) {
            return response()->json(['error' => 'Nie znaleziono wiadomości'], 404);
        }

        return response()->json($message);
    }

    /**
     * Wyślij email ze skrzynki (nowa wiadomość lub odpowiedź)
     */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'to_email' => 'required|email|max:255',
            'to_name' => 'nullable|string|max:255',
            'subject' => 'required|string|max:500',
            'html_content' => 'required|string|max:50000',
            'mail_config_id' => 'required|exists:user_mail_configs,id',
        ]);

        $user = auth()->user();
        $mailConfig = $user->mailConfigs()->verified()->findOrFail($validated['mail_config_id']);

        try {
            $this->mailService->send(
                user: $user,
                toEmail: $validated['to_email'],
                subject: trim($validated['subject']),
                htmlContent: trim($validated['html_content']),
                toName: $validated['to_name'] ?: null,
                mailConfig: $mailConfig
            );

            return response()->json(['success' => true, 'message' => 'Wiadomość została wysłana.']);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
