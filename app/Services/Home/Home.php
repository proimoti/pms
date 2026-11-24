<?php

namespace App\Services\Home;

use App\Enums\ChargeType;
use App\Enums\PaymentMethod;
use App\Models\Beneficiary;
use App\Models\ChargeDraft;
use App\Models\ChargeMonth;
use App\Models\Concerns\WorksheetRules;
use App\Models\OwnerContract;
use App\Models\PeriodLock;
use App\Models\Property;
use App\Models\UtilityImportIssue;
use App\Services\Charges\Cents;
use App\Services\Charges\Month;
use App\Services\Charges\MonthWorksheet;
use App\Services\Reports\Reports;
use App\Services\Utilities\UnallocatedUtilities;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * Данните за „Начало“ (PROJECT.md, „Поправки по екраните“, 24.11.2026). Само
 * чете, през сегашните услуги и филтрите по фирма и „само прикачените имоти“.
 * Месецът е този, който работната таблица отваря (следващият); сумите са
 * като в нея: наем, към собствениците и приходът (наем, неустойки и „Друго“
 * минус дължимото към собственика).
 */
final class Home
{
    public function __construct(
        private readonly Reports $reports,
        private readonly MonthWorksheet $sheet,
        private readonly UnallocatedUtilities $unallocated,
    ) {}

    public function summary(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $month = $today->startOfMonth()->addMonth();
        $key = Month::key($month);
        $vacant = $this->reports->vacant(false, $today);
        $total = Property::query()->whereNull('archived_at')->count();
        $occupied = $total - count($vacant);

        return [
            'today' => $today,
            'month' => $month,
            'key' => $key,
            'state' => PeriodLock::isLocked($month) ? 'issued' : (ChargeMonth::query()->whereDate('month', $month)->exists() ? 'generated' : 'none'),
            'occupancy' => ['occupied' => $occupied, 'total' => $total, 'percent' => $total > 0 ? intdiv($occupied * 100, $total) : 0],
            'totals' => $this->totals($month),
            'decide' => $this->decide($month, $key),
            'follow' => $this->follow($today, count($vacant)),
            'canManage' => WorksheetRules::canManage(Auth::user()),
        ];
    }

    /** @return ?array{rent: string, owners: string, commission: string} null за негенериран месец */
    private function totals(CarbonImmutable $month): ?array
    {
        if (! ChargeMonth::query()->whereDate('month', $month)->exists()) {
            return null;
        }
        $rows = ChargeDraft::query()->whereDate('month', $month)->whereNull('removed_at')->get(['type', 'amount']);
        $sum = fn (array $types) => $rows->filter(fn ($row) => in_array($row->type, $types, true))->sum(fn ($row) => Cents::from($row->amount));
        $owners = $sum([ChargeType::Owner]);

        return [
            'rent' => Cents::format($sum([ChargeType::Rent])),
            'owners' => Cents::format($owners),
            'commission' => Cents::format($sum([ChargeType::Rent, ChargeType::Penalty, ChargeType::Other]) - $owners),
        ];
    }

    /** @return list<array{key: string, count: int, url: string, tone: string}> само ненулевите */
    private function decide(CarbonImmutable $month, string $key): array
    {
        $rows = [];
        if (WorksheetRules::canManage(Auth::user())) {
            $rows[] = ['key' => 'import', 'count' => UtilityImportIssue::query()->where('status', UtilityImportIssue::OPEN)->count(), 'url' => '/utility-issues', 'tone' => 'warn'];
        }
        $rows[] = ['key' => 'unallocated', 'count' => count($this->unallocated->items()['decide']), 'url' => '/utility-unallocated', 'tone' => 'warn'];
        $rows[] = ['key' => 'contracts', 'count' => count($this->sheet->forDecision($key)), 'url' => '/charges?month='.$key.'#for-decision', 'tone' => 'warn'];
        $rows[] = ['key' => 'no_iban', 'count' => $this->withoutIban($month->endOfMonth()->startOfDay()), 'url' => '/reports/payouts?month='.$month->format('m.Y'), 'tone' => 'error'];

        return array_values(array_filter($rows, fn ($row) => $row['count'] > 0));
    }

    /** Действащи договори за управление с плащане по банка към бенефициент без IBAN (на датата). */
    private function withoutIban(CarbonImmutable $day): int
    {
        return OwnerContract::query()->whereNull('archived_at')->get()
            ->filter(fn (OwnerContract $contract) => $contract->isActiveOn($day))
            ->map(fn (OwnerContract $contract) => $contract->payeeOn($day))
            ->filter(fn ($payee) => $payee !== null && $payee->payment_method === PaymentMethod::Bank)
            ->filter(function ($payee) {
                $beneficiary = $payee->beneficiary_id ? Beneficiary::query()->find($payee->beneficiary_id) : null;

                return $beneficiary === null || in_array($beneficiary->getRawOriginal('iban'), [null, ''], true);
            })
            ->count();
    }

    private function follow(CarbonImmutable $today, int $vacant): array
    {
        return [
            'expiring' => $this->reports->expiring(30, $today),
            'expired' => $this->reports->expired(null, $today),
            'vacant' => $vacant,
        ];
    }
}
