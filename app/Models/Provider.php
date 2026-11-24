<?php

namespace App\Models;

use App\Models\Concerns\RegistryRecord;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Доставчик на битова услуга (PROJECT.md, т. 5.3, 21.11.2026): име в
 * приложението и постоянните имена във файловете на EasyPay („Търговец“) и
 * ePay („Кореспондент“). По тях вносът познава доставчика. Всяко име е
 * уникално във фирмата. Виждат го всички служители на фирмата.
 */
class Provider extends Model
{
    use RegistryRecord;

    /** Файловете за внос и колоната с името на доставчика в тях. */
    public const FILE_COLUMNS = ['easypay' => 'easypay_name', 'epay' => 'epay_name'];

    protected $table = 'providers';

    protected $fillable = ['name', 'kind', 'easypay_name', 'epay_name', 'note'];

    /** Вид услуга (т. 2.13, 24.11.2026): един доставчик – една услуга; попълва се сам в новия абонат. */
    protected function casts(): array
    {
        return ['kind' => \App\Enums\UtilityKind::class];
    }

    protected static function booted(): void
    {
        static::saving(function (Provider $provider) {
            $errors = [];

            foreach (['name', 'easypay_name', 'epay_name'] as $field) {
                $value = $provider->getAttribute($field);
                if ($value === null) {
                    continue;
                }

                $taken = static::query()->withoutGlobalScopes()
                    ->where('company_id', $provider->company_id)
                    ->where($field, $value)
                    ->when($provider->exists, fn ($query) => $query->whereKeyNot($provider->getKey()))
                    ->exists();

                if ($taken) {
                    $errors[$field] = __('utilities.errors.provider_name_taken');
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        });
    }

    /**
     * Без невидими интервали (NBSP, нови редове) в началото и края и без
     * двойни интервали вътре. Празно = няма име.
     */
    public static function normalizeName(?string $name): ?string
    {
        $clean = trim((string) preg_replace('/[\s\x{00A0}\x{2007}\x{202F}\x{FEFF}]+/u', ' ', (string) $name));

        return $clean === '' ? null : $clean;
    }

    /** Доставчикът с това име във файла ($file: easypay или epay); без значение от главни и малки букви. */
    public static function fromFile(string $file, ?string $name): ?self
    {
        $name = static::normalizeName($name);
        if ($name === null) {
            return null;
        }

        return static::query()->where(self::FILE_COLUMNS[$file], $name)->first();
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::normalizeName($value) ?? '');
    }

    protected function easypayName(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::normalizeName($value));
    }

    protected function epayName(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::normalizeName($value));
    }
}
