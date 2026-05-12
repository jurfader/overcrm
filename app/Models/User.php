<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'position',
        'role',
        'status',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'avatar',
        'notes',
        'email_html_footer',
        'last_login_at',
        'fakturownia_department_id',
        'fakturownia_department_name',
        'apilo_default_platform_id',
        'play_phone',
        'sip_account',
        'dashboard_layout',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'apilo_default_platform_id' => 'integer',
            'dashboard_layout' => 'array',
        ];
    }

    // ==================== RELACJE ====================

    /**
     * Zadania przypisane do użytkownika
     */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    /**
     * Zadania utworzone przez użytkownika
     */
    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    /**
     * Klienci utworzeni przez użytkownika
     */
    public function createdClients(): HasMany
    {
        return $this->hasMany(Client::class, 'created_by');
    }

    /**
     * Uprawnienia użytkownika
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withTimestamps();
    }

    /**
     * Logi aktywności użytkownika
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Konfiguracje serwerów pocztowych użytkownika
     */
    public function mailConfigs(): HasMany
    {
        return $this->hasMany(UserMailConfig::class);
    }

    /**
     * Domyślna konfiguracja serwera pocztowego
     */
    public function defaultMailConfig(): ?UserMailConfig
    {
        return $this->mailConfigs()->where('is_default', true)->first();
    }

    /**
     * Wysłane emaile przez użytkownika
     */
    public function sentEmails(): HasMany
    {
        return $this->hasMany(SentEmail::class);
    }

    /**
     * Kalendarze (innych userów) do których ten user ma dostęp jako "dodatkowy opiekun".
     * Pozwala zalogowanemu na ?as_user=X w kalendarzu, jeśli X jest w tej liście.
     */
    public function managedCalendars(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'calendar_managers', 'manager_id', 'calendar_user_id')
            ->withTimestamps();
    }

    /**
     * Userzy, którzy mają dostęp do kalendarza tego usera (odwrotna strona).
     */
    public function calendarManagers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'calendar_managers', 'calendar_user_id', 'manager_id')
            ->withTimestamps();
    }

    // ==================== SCOPES ====================

    /**
     * Tylko aktywni użytkownicy
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Tylko administratorzy
     */
    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }

    /**
     * Tylko managerowie
     */
    public function scopeManagers($query)
    {
        return $query->where('role', 'manager');
    }

    // ==================== AKCESORY ====================

    /**
     * Czy użytkownik jest administratorem
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Czy użytkownik ma ukrytą rolę developerską (email w config/developers.php)
     * Ma pełne uprawnienia admina + dodatkowe developerskie.
     */
    public function isDeveloper(): bool
    {
        $emails = config('developers.emails', []);

        return !empty($this->email) && in_array(strtolower($this->email), array_map('strtolower', $emails));
    }

    /**
     * Czy użytkownik ma uprawnienia administratorskie (admin lub developer)
     */
    public function hasAdminRights(): bool
    {
        return $this->isAdmin() || $this->isDeveloper();
    }

    /**
     * Czy użytkownik jest managerem
     */
    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /**
     * Czy użytkownik jest aktywny
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Sprawdza czy użytkownik ma uprawnienie
     */
    public function hasPermission(string $code): bool
    {
        // Administratorzy i developerzy mają wszystkie uprawnienia
        if ($this->isAdmin() || $this->isDeveloper()) {
            return true;
        }

        return $this->permissions()->where('code', $code)->exists();
    }

    /**
     * Sprawdza czy użytkownik może wykonać akcję na module
     */
    public function canAccess(string $module, string $action = 'view'): bool
    {
        return $this->hasPermission("{$module}_{$action}");
    }

    /**
     * Pobierz inicjały użytkownika
     */
    public function getInitialsAttribute(): string
    {
        $words = explode(' ', $this->name);
        $initials = '';
        
        foreach ($words as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }
        
        return mb_substr($initials, 0, 2);
    }

    /**
     * Etykieta roli
     */
    public function getRoleLabelAttribute(): string
    {
        return match($this->role) {
            'admin' => 'Administrator',
            'manager' => 'Manager',
            'user' => 'Użytkownik',
            default => $this->role ?? '',
        };
    }

    /**
     * Etykieta statusu
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'active' => 'Aktywny',
            'inactive' => 'Nieaktywny',
            default => $this->status ?? '',
        };
    }

    /**
     * URL do avatara użytkownika (lub null)
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if (!$this->avatar) {
            return null;
        }

        return asset('storage/' . $this->avatar);
    }

    /**
     * Atrybuty dołączane do serializacji
     */
    protected $appends = ['initials', 'role_label', 'status_label', 'avatar_url'];
}
