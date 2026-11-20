<?php

namespace Tests\Feature\Contracts;

use App\Enums\PaymentMethod;
use App\Models\Beneficiary;
use App\Models\OwnerContractAnnex;
use App\Models\OwnerContractLine;

/**
 * Един анекс с няколко промени наведнъж (както е на хартия): процент, суми,
 * добавени и махнати имоти, бенефициент, удължаване. Промените важат от обща
 * дата, а всеки имот може да има своя. Ако една промяна е грешна, не се
 * записва нищо, и самият анекс (PROJECT.md, т. 2 „Договори“, „Удължаване с анекс“).
 */
class OwnerContractApplyAnnexTest extends ContractTestCase
{
    public function test_one_annex_changes_percent_payee_term_and_properties_together(): void
    {
        $a = $this->property();
        $b = $this->property();
        $new = $this->property();
        $contract = $this->percentContract([$a, $b], ['end_on' => '2026-12-31']);
        $payee = Beneficiary::factory()->create(['name' => 'Измислен Получател']);

        $annex = $this->contracts->applyAnnex($contract, [
            'number' => 1, 'signed_on' => '2026-05-20', 'note' => 'Измислено основание', 'from' => '2026-06-01',
            'commission_percent' => '12',
            'payee' => ['beneficiary_id' => $payee->id, 'payment_method' => 'cash'],
            'end_on' => '2027-12-31',
            'lines' => [
                ['action' => 'add', 'property_id' => $new->id, 'from' => '2026-07-01'],
                ['action' => 'remove', 'property_id' => $b->id],
            ],
        ]);

        $this->assertSame(1, $annex->number);
        $this->assertSame('Измислено основание', $annex->note);
        $this->assertSame('12.00', $contract->commissionOn('2026-06-01'));
        $this->assertSame($payee->id, $contract->payeeOn('2026-06-01')->beneficiary_id);
        $this->assertSame(PaymentMethod::Cash, $contract->payeeOn('2026-06-01')->payment_method);
        $this->assertSame('2027-12-31', $contract->fresh()->end_on->toDateString());
        $this->assertNull($contract->lineOn($b, '2026-06-01'));
        $this->assertNull($contract->lineOn($new, '2026-06-30'));
        $this->assertNotNull($contract->lineOn($new, '2026-07-01'));
        $this->assertNotNull($contract->lineOn($a, '2026-07-01'));
    }

    public function test_one_annex_changes_the_amounts_of_several_properties(): void
    {
        $a = $this->property();
        $b = $this->property();
        $contract = $this->fixedContract([$a, $b]);

        $this->contracts->applyAnnex($contract, [
            'number' => 1, 'signed_on' => '2026-05-20', 'from' => '2026-06-01',
            'lines' => [
                ['action' => 'amount', 'property_id' => $a->id, 'fixed_amount' => '500'],
                ['action' => 'amount', 'property_id' => $b->id, 'fixed_amount' => '480,50'],
            ],
        ]);

        $this->assertSame('500.00', $contract->lineOn($a, '2026-06-01')->fixed_amount);
        $this->assertSame('480.50', $contract->lineOn($b, '2026-06-01')->fixed_amount);
    }

    public function test_extension_only_annex_needs_no_from_date(): void
    {
        $contract = $this->percentContract([$this->property()], ['end_on' => '2026-12-31']);

        $this->contracts->applyAnnex($contract, ['number' => 1, 'signed_on' => '2026-11-15', 'end_on' => '2027-12-31']);

        $this->assertSame('2027-12-31', $contract->fresh()->end_on->toDateString());
    }

    public function test_a_wrong_change_saves_nothing_not_even_the_annex(): void
    {
        $busy = $this->property();
        $this->percentContract([$busy]);
        $contract = $this->percentContract([$this->property()], ['end_on' => '2026-12-31']);

        $this->assertRejected(fn () => $this->contracts->applyAnnex($contract, [
            'number' => 1, 'signed_on' => '2026-05-20', 'from' => '2026-06-01',
            'commission_percent' => '12',
            'end_on' => '2027-12-31',
            'lines' => [['action' => 'add', 'property_id' => $busy->id]],
        ]), 'lines');

        $this->assertSame(0, OwnerContractAnnex::query()->count());
        $this->assertSame('10.00', $contract->commissionOn('2026-07-01'));
        $this->assertSame('2026-12-31', $contract->fresh()->end_on->toDateString());
        $this->assertSame(2, OwnerContractLine::query()->count());
    }

    public function test_changes_need_a_from_date_and_the_annex_needs_a_change(): void
    {
        $contract = $this->percentContract([$this->property()]);

        $this->assertRejected(fn () => $this->contracts->applyAnnex($contract, [
            'number' => 1, 'signed_on' => '2026-05-20', 'commission_percent' => '12',
        ]), 'from');
        $this->assertRejected(fn () => $this->contracts->applyAnnex($contract, [
            'number' => 1, 'signed_on' => '2026-05-20', 'from' => '2026-06-01',
        ]), 'changes');
        $this->assertSame(0, OwnerContractAnnex::query()->count());
    }

    public function test_unknown_line_action_is_refused(): void
    {
        $property = $this->property();
        $contract = $this->percentContract([$property]);

        $this->assertRejected(fn () => $this->contracts->applyAnnex($contract, [
            'number' => 1, 'signed_on' => '2026-05-20', 'from' => '2026-06-01',
            'lines' => [['action' => 'something', 'property_id' => $property->id]],
        ]), 'lines');
    }
}
