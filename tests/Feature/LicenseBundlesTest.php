<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\LicenseService;
use App\Support\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pakiety licencyjne — od tego zależy, za co klient zapłacił.
 *
 * Dwie rzeczy są tu nie do ruszenia: nie da się nadać sobie pakietu edytując
 * bazę, oraz wygaśnięcie licencji odbiera dostęp do pakietów. Reszta to wygoda.
 */
class LicenseBundlesTest extends TestCase
{
    use RefreshDatabase;

    protected LicenseService $license;

    protected function setUp(): void
    {
        parent::setUp();

        $this->license = app(LicenseService::class);
    }

    protected function activateWithBundles(array $bundles): void
    {
        Setting::set('license_key', 'TEST-TEST-TEST-TEST');
        Setting::set('license_status', LicenseService::STATUS_ACTIVE);
        Setting::set('license_expires_at', now()->addYear()->toIso8601String());
        Setting::set('license_plan', 'pro');
        Setting::set('license_bundles', json_encode($bundles));

        (new ReflectionMethod($this->license, 'writeStateLock'))->invoke($this->license);
        (new ReflectionMethod($this->license, 'writeBundlesLock'))->invoke($this->license);

        License::reset();
        Cache::flush();
    }

    public function test_wykupione_pakiety_sa_dostepne(): void
    {
        $this->activateWithBundles(['overcrm-ai', 'overcrm-telefonia']);

        $this->assertSame(['overcrm-ai', 'overcrm-telefonia'], $this->license->bundles());
        $this->assertTrue($this->license->hasBundle('overcrm-ai'));
        $this->assertTrue($this->license->hasBundle('overcrm-telefonia'));
        $this->assertFalse($this->license->hasBundle('overcrm-analityka'));
    }

    public function test_pakiet_podstawowy_nie_wymaga_osobnego_uprawnienia(): void
    {
        $this->activateWithBundles([]);

        // Moduły wliczone w licencję CRM-a muszą działać u każdego klienta.
        $this->assertTrue($this->license->hasBundle('overcrm-core'));
        $this->assertTrue($this->license->hasBundle(null));
    }

    public function test_dopisanie_pakietu_w_bazie_nic_nie_daje(): void
    {
        $this->activateWithBundles(['overcrm-ai']);

        // Ktoś dopisuje sobie płatny pakiet wprost w tabeli settings,
        // ale nie potrafi przeliczyć HMAC bez APP_KEY.
        Setting::set('license_bundles', json_encode(['overcrm-ai', 'overcrm-analityka']));
        Cache::flush();

        // Zamek nie pasuje → odrzucamy CAŁĄ listę, nie tylko dopisany wpis.
        $this->assertSame([], $this->license->bundles());
        $this->assertFalse($this->license->hasBundle('overcrm-analityka'));
        $this->assertFalse($this->license->hasBundle('overcrm-ai'));
    }

    public function test_wygasla_licencja_odbiera_pakiety(): void
    {
        $this->activateWithBundles(['overcrm-ai']);
        $this->assertTrue($this->license->hasBundle('overcrm-ai'));

        Setting::set('license_status', LicenseService::STATUS_EXPIRED);
        (new ReflectionMethod($this->license, 'writeStateLock'))->invoke($this->license);
        License::reset();
        Cache::flush();

        $this->assertSame([], $this->license->bundles());
        $this->assertFalse($this->license->hasBundle('overcrm-ai'));
    }

    public function test_brak_pakietow_nie_psuje_licencji(): void
    {
        // Starszy serwer licencji nie zna pola 'bundles' — instalacja musi
        // działać normalnie, tylko bez płatnych pakietów.
        Setting::set('license_key', 'TEST-TEST-TEST-TEST');
        Setting::set('license_status', LicenseService::STATUS_ACTIVE);
        Setting::set('license_expires_at', now()->addYear()->toIso8601String());
        (new ReflectionMethod($this->license, 'writeStateLock'))->invoke($this->license);
        License::reset();
        Cache::flush();

        $this->assertTrue($this->license->isValid());
        $this->assertSame([], $this->license->bundles());
        $this->assertTrue($this->license->hasBundle('overcrm-core'));
    }
}
