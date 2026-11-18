<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\RegistryRecord;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Support\Iban;
use Database\Factories\BeneficiaryFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Бенефициент: получател на плащането към собственика (PROJECT.md, т. 2).
 *
 * IBAN е шифрован в базата, вижда се и се сменя само с правото за ЕГН и IBAN,
 * не излиза в списъците (toArray/JSON) и в журнала. Засега без екран.
 */
#[UseFactory(BeneficiaryFactory::class)]
class Beneficiary extends Model
{
    /** @use HasFactory<BeneficiaryFactory> */
    use HasFactory, RegistryRecord;

    protected $table = 'beneficiaries';

    protected $fillable = ['name', 'iban'];

    protected $hidden = ['iban'];

    /** В журнала само „сменен“, без стойност. */
    protected array $auditProtected = ['iban'];

    protected static function assignedScopeRule(): string
    {
        return RegistryVisibilityScope::NONE;
    }

    protected function iban(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null && self::currentUserMaySeeSensitive()
                ? Crypt::decryptString($value)
                : null,
            set: function (?string $value) {
                if (! self::currentUserMaySeeSensitive()) {
                    throw new AuthorizationException(__('registry.sensitive_only'));
                }

                if ($value === null || trim($value) === '') {
                    return null;
                }

                $iban = Iban::normalize($value);

                if (! Iban::isValid($iban)) {
                    // Самият IBAN не се показва в съобщението (и не стига до логовете).
                    throw ValidationException::withMessages(['iban' => __('registry.iban_invalid')]);
                }

                return Crypt::encryptString($iban);
            },
        );
    }

    /** Командите на сървъра (без влязъл потребител) имат достъп, например за внос. */
    private static function currentUserMaySeeSensitive(): bool
    {
        if (! Auth::hasUser()) {
            return true;
        }

        $user = Auth::user();

        return $user->role === Role::Staff && $user->can_view_sensitive;
    }
}
