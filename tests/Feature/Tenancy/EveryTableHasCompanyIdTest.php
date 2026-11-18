<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Предпазен тест: никоя таблица не остава без company_id по невнимание.
 *
 * Ако добавите таблица без company_id, тестът пада. Тогава или се добавя
 * company_id, или таблицата влиза в EXCEPTIONS с ясна причина.
 */
class EveryTableHasCompanyIdTest extends TestCase
{
    use RefreshDatabase;

    /** Таблици без company_id. Всяко изключение има причина. */
    private const EXCEPTIONS = [
        'companies' => 'Самите фирми: това е таблицата, към която сочи company_id.',
        'migrations' => 'Служебна таблица на Laravel: кои миграции са пуснати.',
        'password_reset_tokens' => 'Временни кодове за смяна на парола. Търсят се по имейл, който е уникален в цялото приложение.',
        'sessions' => 'Техническа: сесии за вход. Сочат към потребител, който си има фирма.',
        'cache' => 'Техническа: временен кеш на приложението.',
        'cache_locks' => 'Техническа: заключвания на кеша.',
        'jobs' => 'Техническа: опашка със задачи за изпълнение.',
        'job_batches' => 'Техническа: групи от задачи.',
        'failed_jobs' => 'Техническа: задачи, които не са успели.',
    ];

    public function test_every_table_has_company_id_unless_it_is_a_listed_exception(): void
    {
        $missing = array_filter(
            $this->tables(),
            fn (string $table) => ! array_key_exists($table, self::EXCEPTIONS)
                && ! Schema::hasColumn($table, 'company_id'),
        );

        $this->assertSame([], array_values($missing),
            'Тези таблици нямат company_id и не са в списъка с изключения.');
    }

    public function test_every_exception_has_a_reason(): void
    {
        foreach (self::EXCEPTIONS as $table => $reason) {
            $this->assertNotSame('', trim($reason), "Изключението „{$table}“ няма причина.");
        }
    }

    public function test_every_exception_is_an_existing_table(): void
    {
        $unknown = array_diff(array_keys(self::EXCEPTIONS), $this->tables());

        $this->assertSame([], array_values($unknown),
            'Тези изключения не съществуват като таблици. Махнете ги от списъка.');
    }

    public function test_every_company_id_points_to_companies(): void
    {
        foreach ($this->tables() as $table) {
            if (! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            $pointsToCompanies = collect(Schema::getForeignKeys($table))->contains(
                fn (array $fk) => $fk['columns'] === ['company_id']
                    && $fk['foreign_table'] === 'companies'
                    && $fk['foreign_columns'] === ['id'],
            );

            $this->assertTrue($pointsToCompanies, "{$table}.company_id не е свързано с companies.id.");
        }
    }

    /**
     * Връзка между две фирмени таблици включва и фирмата (PROJECT.md, т. 2 „Регистър“),
     * за да не може запис да сочи към запис на друга фирма.
     */
    public function test_every_link_between_company_tables_includes_company_id(): void
    {
        $companyTables = array_values(array_filter($this->tables(), fn ($t) => Schema::hasColumn($t, 'company_id')));
        $this->assertContains('brokers', $companyTables, 'Проверката трябва да има поне една таблица от регистъра.');

        $bad = [];
        foreach ($companyTables as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                $pointsToCompanyTable = in_array($fk['foreign_table'], $companyTables, true);
                $includesCompany = in_array('company_id', $fk['columns'], true)
                    && in_array('company_id', $fk['foreign_columns'], true);

                if ($pointsToCompanyTable && ! $includesCompany) {
                    $bad[] = $table.'('.implode(',', $fk['columns']).') → '.$fk['foreign_table'];
                }
            }
        }

        $this->assertSame([], $bad, 'Тези връзки не включват company_id.');
    }

    public function test_every_model_with_company_id_uses_the_company_filter(): void
    {
        foreach (File::files(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();
            $model = new $class;

            if (! Schema::hasColumn($model->getTable(), 'company_id')) {
                continue;
            }

            $this->assertContains(BelongsToCompany::class, class_uses_recursive($class),
                "{$class} има company_id, но не ползва автоматичния филтър по фирма.");
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        return Schema::getTableListing(DB::connection()->getDatabaseName(), false);
    }
}
