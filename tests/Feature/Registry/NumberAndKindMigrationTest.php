<?php

namespace Tests\Feature\Registry;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Какво правят миграциите от 24.11.2026 със сегашните данни (PROJECT.md,
 * т. 2.4, 2.7 и 2.13): бенефициентите и отговорните лица получават 1, 2, 3…
 * по реда на създаване, отделно за всяка фирма (заетите номера се пропускат);
 * доставчикът получава вида на абонатите си само ако всички са от един вид.
 * Всяка промяна е в журнала. Записите „отпреди“ се правят направо в базата.
 * Само измислени данни.
 */
class NumberAndKindMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}.php");
    }

    private function old(string $table, int $companyId, string $name, string $createdAt, ?int $number = null): int
    {
        return DB::table($table)->insertGetId(['company_id' => $companyId, 'number' => $number, 'name' => $name, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
    }

    public function test_old_beneficiaries_and_brokers_get_numbers_by_creation_order_per_company(): void
    {
        [$a, $b] = [Company::factory()->create()->id, Company::factory()->create()->id];
        $second = $this->old('beneficiaries', $a, 'Втори Получател', '2026-11-20 10:00:00');
        $first = $this->old('beneficiaries', $a, 'Първи Получател', '2026-11-19 10:00:00');
        $taken = $this->old('beneficiaries', $a, 'С Номер', '2026-11-18 10:00:00', 2);
        $other = $this->old('beneficiaries', $b, 'Чужд Получател', '2026-11-21 10:00:00');
        $broker = $this->old('brokers', $a, 'Иво Измислен', '2026-11-19 10:00:00');

        $this->migration('2026_11_24_000001_add_numbers_to_beneficiaries_and_brokers')->backfill();

        $numbers = DB::table('beneficiaries')->pluck('number', 'id');
        $this->assertSame([1, 3, 2, 1], [(int) $numbers[$first], (int) $numbers[$second], (int) $numbers[$taken], (int) $numbers[$other]]);
        $this->assertSame(1, (int) DB::table('brokers')->where('id', $broker)->value('number'));
        $this->assertSame(4, DB::table('audit_log')->where('event', 'updated')->whereIn('auditable_type', ['beneficiaries', 'brokers'])->count());

        // Втори път нищо не се променя.
        $this->migration('2026_11_24_000001_add_numbers_to_beneficiaries_and_brokers')->backfill();
        $this->assertEquals($numbers, DB::table('beneficiaries')->pluck('number', 'id'));
    }

    public function test_provider_gets_the_kind_only_when_all_its_accounts_have_one_kind(): void
    {
        $company = Company::factory()->create()->id;
        $provider = fn (string $name, ?string $kind = null) => DB::table('providers')->insertGetId(['company_id' => $company, 'name' => $name, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now()]);
        $account = fn (int $providerId, string $kind, string $number) => DB::table('utility_accounts')->insert(['company_id' => $company, 'provider_id' => $providerId,
            'number' => $number, 'number_key' => $number, 'kind' => $kind, 'billing' => 'actual', 'period_start_day' => 1, 'valid_from' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now()]);

        $water = $provider('Измислена Вода');
        $account($water, 'water', '1');
        $account($water, 'water', '2');
        $mixed = $provider('Измислен Смесен');
        $account($mixed, 'water', '3');
        $account($mixed, 'electricity', '4');
        $empty = $provider('Измислен Без Абонати');
        $set = $provider('Измислен Газ', 'gas');
        $account($set, 'heating', '5');

        $this->migration('2026_11_24_000002_add_kind_to_providers')->backfill();

        $kinds = DB::table('providers')->pluck('kind', 'id');
        $this->assertSame(['water', null, null, 'gas'], [$kinds[$water], $kinds[$mixed], $kinds[$empty], $kinds[$set]]);
        $this->assertSame(1, DB::table('audit_log')->where('auditable_type', 'providers')->where('auditable_id', $water)->count());
        $this->assertSame(1, DB::table('utility_accounts')->where('kind', 'electricity')->count()); // видът на абонатите не се мени
    }
}
