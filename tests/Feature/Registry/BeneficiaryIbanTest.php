<?php

namespace Tests\Feature\Registry;

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * IBAN на бенефициента (PROJECT.md, т. 2 „Бенефициенти“): шифрован в базата,
 * вижда се само с правото за ЕГН и IBAN, не излиза в списъците и в журнала.
 */
class BeneficiaryIbanTest extends TestCase
{
    use RefreshDatabase;

    // Измислен IBAN с вярна контролна сума (примерен, не на реална сметка).
    private const IBAN = 'BG80BNBG96611020345678';

    private User $withRight;

    private User $withoutRight;

    protected function setUp(): void
    {
        parent::setUp();
        $company = ['company_id' => Company::factory()->create()->id];
        $this->withRight = User::factory()->canViewSensitive()->create($company);
        $this->withoutRight = User::factory()->create($company);
        $this->actingAs($this->withRight);
    }

    public function test_iban_is_encrypted_in_the_database(): void
    {
        $beneficiary = Beneficiary::factory()->create(['iban' => self::IBAN]);

        $raw = DB::table('beneficiaries')->where('id', $beneficiary->id)->value('iban');

        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString(self::IBAN, $raw);
        $this->assertStringNotContainsString('BNBG', $raw);
    }

    public function test_iban_is_stored_without_spaces_and_in_capitals(): void
    {
        $beneficiary = Beneficiary::factory()->create(['iban' => 'bg80 bnbg 9661 1020 3456 78']);

        $this->assertSame(self::IBAN, $beneficiary->fresh()->iban);
    }

    public function test_valid_ibans_are_accepted(): void
    {
        // Измислени примерни IBAN с вярна контролна сума (mod 97).
        foreach (['BG80BNBG96611020345678', 'GB82WEST12345698765432', 'DE89370400440532013000'] as $iban) {
            $this->assertSame($iban, Beneficiary::factory()->create(['iban' => $iban])->fresh()->iban);
        }
    }

    public function test_iban_with_a_wrong_check_sum_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        Beneficiary::factory()->create(['iban' => 'BG80BNBG96611020345679']); // последната цифра е сменена
    }

    public function test_malformed_iban_is_rejected(): void
    {
        foreach (['BG80', 'BG80BNBG9661102034567!', '1234567890', 'ZZ00ABCD12345678'] as $bad) {
            try {
                Beneficiary::factory()->create(['iban' => $bad]);
                $this->fail("IBAN „{$bad}“ трябваше да бъде отказан.");
            } catch (ValidationException) {
                // очаквано
            }
        }

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_user_with_the_right_sees_the_iban(): void
    {
        $beneficiary = Beneficiary::factory()->create(['iban' => self::IBAN]);

        $this->assertSame(self::IBAN, $beneficiary->fresh()->iban);
    }

    public function test_user_without_the_right_does_not_see_the_iban(): void
    {
        $beneficiary = Beneficiary::factory()->create(['iban' => self::IBAN, 'name' => 'Измислен Получател']);

        $this->actingAs($this->withoutRight);
        $seen = Beneficiary::query()->findOrFail($beneficiary->id);

        $this->assertSame('Измислен Получател', $seen->name);
        $this->assertNull($seen->iban);
    }

    public function test_iban_never_appears_in_lists(): void
    {
        Beneficiary::factory()->create(['iban' => self::IBAN]);

        $list = Beneficiary::query()->get()->toJson();

        $this->assertStringNotContainsString(self::IBAN, $list);
        $this->assertArrayNotHasKey('iban', Beneficiary::query()->first()->toArray());
    }

    public function test_iban_change_is_logged_without_its_value(): void
    {
        $beneficiary = Beneficiary::factory()->create(['iban' => self::IBAN]);

        $beneficiary->update(['iban' => 'GB82WEST12345698765432']);

        $log = json_encode(AuditLog::query()->pluck('changes'));
        $this->assertStringNotContainsString('BNBG', $log);
        $this->assertStringNotContainsString('WEST', $log);
        $this->assertSame(['old' => '[скрито]', 'new' => '[скрито]'],
            AuditLog::query()->where('event', 'updated')->sole()->changes['iban']);
    }

    public function test_user_without_the_right_cannot_change_the_iban(): void
    {
        $beneficiary = Beneficiary::factory()->create(['iban' => self::IBAN]);

        $this->actingAs($this->withoutRight);
        $seen = Beneficiary::query()->findOrFail($beneficiary->id);
        $seen->update(['name' => 'Ново Име']);

        $this->actingAs($this->withRight);
        $this->assertSame(self::IBAN, $beneficiary->fresh()->iban, 'IBAN не трябва да се загуби при промяна от потребител без право.');
    }
}
