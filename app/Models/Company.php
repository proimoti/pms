<?php

namespace App\Models;

use App\Enums\PaymentSource;
use App\Models\Concerns\Audited;
use App\Models\Concerns\CompanyAdminOnly;
use App\Models\Scopes\CompanyScope;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'eik', 'vat_number', 'manager_name', 'address', 'email', 'phone'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use Audited { writeAudit as private writeAuditEntry; }
    use CompanyAdminOnly, HasFactory;

    protected static function booted(): void
    {
        // Потребителят вижда само своята фирма (тук връзката е по id).
        // Администраторът на приложението вижда всички фирми.
        static::addGlobalScope(new CompanyScope('id', appAdminSeesAll: true));
    }

    protected function casts(): array
    {
        return [
            'payment_source' => PaymentSource::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Администраторът на фирмата е нужен само за данните от екрана „Фирма“ на
     * съществуваща фирма; създаването и активността са на администратора на
     * приложението и на командите.
     */
    protected function needsCompanyAdmin(): bool
    {
        return $this->exists && $this->isDirty(['name', 'eik', 'vat_number', 'manager_name', 'address', 'email', 'phone', 'logo_path']);
    }

    /** В журнала са промените на данните (екранът „Фирма“), не създаването на фирмата (с команда). */
    protected function writeAudit(string $event, array $changes): void
    {
        if ($event !== 'created') {
            $this->writeAuditEntry($event, $changes);
        }
    }

    /** За журнала: фирмата на записа е самата фирма. */
    public function getCompanyIdAttribute(): ?int
    {
        return $this->getKey();
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /** Текущото лого като адрес data: за известието (PDF и писмо) или null (25.11.2026). */
    public function logoDataUri(): ?string
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if ($this->logo_path === null || ! $disk->exists($this->logo_path)) {
            return null;
        }
        $mime = str_ends_with($this->logo_path, '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($this->logo_path));
    }

    /** Сметката „за известията“ (неархивирана) или null (PROJECT.md, 24.11.2026). */
    public function statementAccount(): ?BankAccount
    {
        return $this->bankAccounts()->whereNull('archived_at')->where('for_statements', true)->first();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
