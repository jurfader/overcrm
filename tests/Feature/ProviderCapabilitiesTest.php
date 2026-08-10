<?php

namespace Tests\Feature;

use App\Contracts\NotificationChannel;
use App\Contracts\TelephonyProvider;
use App\Models\User;
use App\Support\Notifications\Notifier;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Warstwa zdolności — fundament uniwersalności modułów.
 *
 * Najważniejsza rzecz do obronienia: instalacja BEZ modułu telefonii czy AI
 * musi działać normalnie. Jeśli brak modułu zacznie rzucać wyjątkiem, każdy
 * kolejny moduł funkcjonalny zwiąże się na sztywno z konkretną marką i cała
 * idea `requires: capability:*` przestanie mieć sens.
 */
class ProviderCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(ProviderRegistry::class);
    }

    public function test_zdolnosci_rdzenia_sa_zawsze_dostepne(): void
    {
        $this->assertTrue($this->registry->has('product'));
        $this->assertTrue($this->registry->has('order'));
        $this->assertTrue($this->registry->has('storage'));

        $this->assertInstanceOf(
            \App\Contracts\StorageProvider::class,
            $this->registry->active('storage')
        );
    }

    public function test_brak_uzywalnej_telefonii_nie_wywraca_aplikacji(): void
    {
        // W tym środowisku moduł Play Centrali jest obecny, ale bez kluczy API.
        // Z punktu widzenia funkcji to dokładnie to samo, co brak centrali.
        $this->assertFalse($this->registry->has('telephony'));
        $this->assertNull($this->registry->activeOrNull('telephony'));
        $this->assertNotContains('telephony', $this->registry->capabilities());

        // Binding kontraktu też musi oddać null, a nie rzucić.
        $this->assertNull(app(TelephonyProvider::class));
    }

    public function test_brak_modulu_ai_nie_wywraca_aplikacji(): void
    {
        $this->assertFalse($this->registry->has('ai'));
        $this->assertNull(app(\App\Contracts\AiProvider::class));
    }

    public function test_zarejestrowany_modul_telefonii_wnosi_zdolnosc(): void
    {
        $this->registry->register('telephony', 'fake', FakeTelephonyProvider::class);
        $this->registry->setActive('telephony', 'fake');

        $this->assertTrue($this->registry->has('telephony'));
        $this->assertContains('telephony', $this->registry->capabilities());
        $this->assertInstanceOf(FakeTelephonyProvider::class, app(TelephonyProvider::class));
    }

    public function test_provider_bez_konfiguracji_nie_liczy_sie_jako_zdolnosc(): void
    {
        // Moduł zainstalowany, ale klient nie wpisał kluczy API — z punktu
        // widzenia funkcji to to samo, co brak modułu.
        $this->registry->register('telephony', 'fake', UnconfiguredTelephonyProvider::class);

        $this->assertFalse($this->registry->has('telephony'));
        $this->assertNotContains('telephony', $this->registry->capabilities());
    }

    public function test_kanaly_powiadomien_dzialaja_rownolegle(): void
    {
        $this->registry->register('notification', 'fake-push', FakePushChannel::class);
        $this->registry->setEnabled('notification', ['mail', 'fake-push']);

        $keys = array_map(fn ($c) => $c->key(), $this->registry->activeAll('notification'));

        // 'mail' odpada, bo w testach sterownik poczty to 'array' (nic nie wysyła),
        // a kanał uczciwie zgłasza brak gotowości.
        $this->assertContains('fake-push', $keys);
    }

    public function test_notifier_wysyla_wlaczonymi_kanalami(): void
    {
        $this->registry->register('notification', 'fake-push', FakePushChannel::class);
        $this->registry->setEnabled('notification', ['fake-push']);

        $user = User::create([
            'name' => 'Handlowiec',
            'email' => 'handlowiec@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'user',
            'status' => 'active',
        ]);

        FakePushChannel::$sent = [];

        $result = app(Notifier::class)->send($user, 'Przypomnienie', 'Telefon do klienta');

        $this->assertSame(['fake-push' => true], $result);
        $this->assertCount(1, FakePushChannel::$sent);
        $this->assertSame('Przypomnienie', FakePushChannel::$sent[0]['title']);
    }

    public function test_notifier_nie_wywraca_sie_gdy_zaden_kanal_nie_dziala(): void
    {
        $this->registry->setEnabled('notification', []);

        $user = User::create([
            'name' => 'Handlowiec',
            'email' => 'nikt@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'user',
            'status' => 'active',
        ]);

        // Powiadomienie jest efektem ubocznym — brak kanału to pusty wynik,
        // nigdy wyjątek, bo inaczej wywróciłby operację biznesową.
        $this->assertSame([], app(Notifier::class)->send($user, 'Tytuł', 'Treść'));
        $this->assertFalse(app(Notifier::class)->canReach($user));
    }
}

class FakeTelephonyProvider implements TelephonyProvider
{
    public function key(): string { return 'fake'; }
    public function label(): string { return 'Atrapa centrali'; }
    public function isAvailable(): bool { return true; }
    public function callModel(): string { return \App\Models\User::class; }
    public function callColumns(): array { return ['id' => 'id']; }
    public function supportsRecordings(): bool { return true; }
    public function downloadRecording(string $callId): ?string { return null; }
    public function supportsClickToCall(): bool { return false; }
    public function click2call(User $user, string $number): bool { return false; }
    public function syncSince(\DateTimeInterface $since): int { return 0; }
}

class UnconfiguredTelephonyProvider extends FakeTelephonyProvider
{
    public function isAvailable(): bool { return false; }
}

class FakePushChannel implements NotificationChannel
{
    /** @var array<int, array<string, mixed>> */
    public static array $sent = [];

    public function key(): string { return 'fake-push'; }
    public function label(): string { return 'Atrapa push'; }
    public function isAvailable(): bool { return true; }
    public function canReach(User $user): bool { return true; }

    public function send(User $user, string $title, string $body, array $options = []): bool
    {
        self::$sent[] = ['user' => $user->id, 'title' => $title, 'body' => $body];

        return true;
    }
}
