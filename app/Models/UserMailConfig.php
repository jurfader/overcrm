<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class UserMailConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'mail_from_address',
        'mail_from_name',
        'is_default',
        'is_verified',
        'verified_at',
    ];

    protected $casts = [
        'mail_port' => 'integer',
        'is_default' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    protected $hidden = [
        'mail_password',
    ];

    // Szyfrowanie hasła
    public function setMailPasswordAttribute($value)
    {
        $this->attributes['mail_password'] = Crypt::encryptString($value);
    }

    // Deszyfrowanie hasła
    public function getDecryptedPassword(): string
    {
        return Crypt::decryptString($this->attributes['mail_password']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sentEmails(): HasMany
    {
        return $this->hasMany(SentEmail::class);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Ustaw jako domyślną konfigurację
     */
    public function setAsDefault(): void
    {
        // Usuń flagę domyślną z innych konfiguracji użytkownika
        self::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    /**
     * Pobierz konfigurację jako tablicę dla Symfony Mailer
     */
    public function toMailerConfig(): array
    {
        return [
            'transport' => 'smtp',
            'host' => $this->mail_host,
            'port' => $this->mail_port,
            'encryption' => $this->mail_encryption,
            'username' => $this->mail_username,
            'password' => $this->getDecryptedPassword(),
            'from' => [
                'address' => $this->mail_from_address,
                'name' => $this->mail_from_name,
            ],
        ];
    }
}
