<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'subject',
        'description',
        'html_content',
        'variables',
        'category',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    // Dostępne zmienne do użycia w szablonach
    public static array $availableVariables = [
        '{{client_name}}' => 'Nazwa klienta',
        '{{client_email}}' => 'Email klienta',
        '{{client_phone}}' => 'Telefon klienta',
        '{{client_nip}}' => 'NIP klienta',
        '{{client_address}}' => 'Adres klienta',
        '{{visit_date}}' => 'Data wizyty',
        '{{visit_time}}' => 'Godzina wizyty',
        '{{visit_title}}' => 'Tytuł wizyty',
        '{{visit_notes}}' => 'Notatki do wizyty',
        '{{user_name}}' => 'Imię i nazwisko wysyłającego',
        '{{user_email}}' => 'Email wysyłającego',
        '{{user_phone}}' => 'Telefon wysyłającego',
        '{{current_date}}' => 'Aktualna data',
        '{{company_name}}' => 'Nazwa firmy (CHICKENKING)',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($template) {
            if (empty($template->slug)) {
                $template->slug = Str::slug($template->name);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sentEmails(): HasMany
    {
        return $this->hasMany(SentEmail::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Renderuj szablon z danymi
     */
    public function render(array $data): string
    {
        $content = $this->html_content;
        
        foreach ($data as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value ?? '', $content);
        }
        
        return $content;
    }

    /**
     * Pobierz ścieżki załączników z opisu (format migracji: "Załącznik (stary system): path1|path2|...")
     *
     * @return string[] Np. ['web/files/karta_produktow_1.pdf', 'web/files/karta_produktow_2.pdf']
     */
    public function getLegacyAttachmentPaths(): array
    {
        $desc = $this->description;
        if (!$desc || !str_contains($desc, 'Załącznik (stary system):')) {
            return [];
        }
        if (!preg_match('/Załącznik \(stary system\):\s*(.+)/u', $desc, $m)) {
            return [];
        }
        $paths = array_map('trim', explode('|', trim($m[1])));
        return array_filter($paths);
    }

    /**
     * Renderuj temat z danymi
     */
    public function renderSubject(array $data): string
    {
        $subject = $this->subject;
        
        foreach ($data as $key => $value) {
            $subject = str_replace('{{' . $key . '}}', $value ?? '', $subject);
        }
        
        return $subject;
    }
}
