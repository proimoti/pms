<?php

namespace Tests\Unit;

use App\Support\BgDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Датите във вида дд.мм.гггг (PROJECT.md, т. 2 „Дати в интерфейса“). */
class BgDateTest extends TestCase
{
    public function test_reads_day_month_year_with_or_without_leading_zeros(): void
    {
        $this->assertSame('2026-12-31', BgDate::parse('31.12.2026')->toDateString());
        $this->assertSame('2026-02-01', BgDate::parse('1.2.2026')->toDateString());
        $this->assertSame('2026-02-01', BgDate::parse(' 01.02.2026 ')->toDateString());
    }

    public function test_also_reads_the_internal_form_year_month_day(): void
    {
        $this->assertSame('2026-01-10', BgDate::parse('2026-01-10')->toDateString());
    }

    public function test_refuses_dates_that_do_not_exist_or_are_in_another_form(): void
    {
        foreach (['31.02.2026', '29.02.2026', '32.01.2026', '10.13.2026', '00.01.2026', '2026-02-30',
            '10/01/2026', '10.01.26', '10-01-2026', 'abc', '10.01.2026г.', ''] as $wrong) {
            $this->assertNull(BgDate::parse($wrong), "Прие „{$wrong}“.");
        }

        $this->assertSame('2028-02-29', BgDate::parse('29.02.2028')->toDateString());
    }

    public function test_shows_dates_as_day_month_year(): void
    {
        $this->assertSame('10.01.2026', BgDate::toInput(CarbonImmutable::parse('2026-01-10')));
        $this->assertSame('10.01.2026', BgDate::toInput('2026-01-10'));
        // Грешно въведеното остава, както е написано, за да се поправи.
        $this->assertSame('31.02.2026', BgDate::toInput('31.02.2026'));
        $this->assertSame('', BgDate::toInput(null));
    }
}
