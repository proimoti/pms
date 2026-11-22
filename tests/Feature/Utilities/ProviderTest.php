<?php

namespace Tests\Feature\Utilities;

use App\Models\Provider;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Contracts\ContractTestCase;

/**
 * Доставчици на битови услуги (PROJECT.md, т. 3.4 и т. 5.3, „Внос на платените
 * сметки“, 21.11.2026): три имена — в приложението, в EasyPay и в ePay.
 * Имената във файловете са постоянни и по тях вносът познава доставчика.
 * Само измислени имена.
 */
class ProviderTest extends ContractTestCase
{
    public function test_provider_has_three_names(): void
    {
        $provider = Provider::query()->create([
            'name' => 'Измислено Електро', 'easypay_name' => 'IZMISLENO ELEKTRO AD', 'epay_name' => 'Измислено Електро АД',
        ]);

        $this->assertSame($this->staff->company_id, $provider->company_id);
        $this->assertSame('IZMISLENO ELEKTRO AD', $provider->easypay_name);
        $this->assertSame('Измислено Електро АД', $provider->epay_name);
    }

    public function test_names_are_cleaned_of_invisible_spaces(): void
    {
        // Във файловете на EasyPay има неразделими интервали (NBSP) отпред и отзад.
        $provider = Provider::query()->create([
            'name' => "  Измислена  Вода ", 'easypay_name' => "\u{00A0}IZMISLENA VODA\u{00A0}", 'epay_name' => "Измислена\u{00A0}Вода ЕАД\n",
        ]);

        $this->assertSame('Измислена Вода', $provider->name);
        $this->assertSame('IZMISLENA VODA', $provider->easypay_name);
        $this->assertSame('Измислена Вода ЕАД', $provider->epay_name);
    }

    public function test_file_names_are_optional(): void
    {
        $provider = Provider::query()->create(['name' => 'Входна такса', 'easypay_name' => '', 'epay_name' => ' ']);

        $this->assertNull($provider->easypay_name);
        $this->assertNull($provider->epay_name);
    }

    public function test_each_name_is_unique_in_the_company(): void
    {
        Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL', 'epay_name' => 'Измислено Електро АД']);

        foreach ([
            'name' => ['name' => 'измислено електро'],
            'easypay_name' => ['name' => 'Друг 1', 'easypay_name' => "\u{00A0}IZM EL"],
            'epay_name' => ['name' => 'Друг 2', 'epay_name' => 'Измислено Електро АД'],
        ] as $field => $values) {
            try {
                Provider::query()->create($values);
                $this->fail("Повторено име ($field) е прието.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->assertSame(1, Provider::query()->count());
    }

    public function test_another_company_may_use_the_same_names(): void
    {
        Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL']);
        $this->actingAs($this->otherStaff);

        Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL']);

        $this->assertSame(1, Provider::query()->count());
    }

    public function test_finds_the_provider_by_the_name_in_each_file(): void
    {
        $provider = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL', 'epay_name' => 'Измислено Електро АД']);
        Provider::query()->create(['name' => 'Друг', 'easypay_name' => 'DRUG']);

        $this->assertSame($provider->id, Provider::fromFile('easypay', "\u{00A0}izm el\u{00A0}")?->id);
        $this->assertSame($provider->id, Provider::fromFile('epay', 'Измислено Електро АД')?->id);
        $this->assertNull(Provider::fromFile('epay', 'IZM EL'));
        $this->assertNull(Provider::fromFile('easypay', 'Непознат'));
    }
}
