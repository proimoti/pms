<?php

namespace App\Services\Payments;

use App\Models\LeaseContract;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Много плащания наведнъж (26.11.2026): общи дата, начин и банкова сметка, които
 * всеки ред може да смени; договорът — по номер („12“ или „12 — Име“) или по
 * точното име на наемателя (ако той има един договор). Първо се проверяват
 * всички редове; при грешка в който и да е ред не се записва нищо, а грешките
 * са по редове (rows.N.поле). Всеки ред е отделно плащане (PaymentService).
 */
final class BatchPayments
{
    public function __construct(private readonly PaymentService $payments) {}

    /** @return list<Payment> */
    public function record(array $common, array $rows): array
    {
        $errors = [];
        $valid = [];
        foreach (array_values($rows) as $i => $row) {
            $row = is_array($row) ? $row : [];
            if (trim((string) ($row['contract'] ?? '')) === '' && trim((string) ($row['amount'] ?? '')) === '') {
                continue; // празен ред
            }
            $data = [
                'amount' => $row['amount'] ?? '',
                'paid_on' => $this->pick($row, $common, 'paid_on'),
                'method' => $this->pick($row, $common, 'method'),
                'bank_account_id' => $this->pick($row, $common, 'bank_account_id'),
                'reference' => $row['reference'] ?? null,
            ];
            [$lease, $contractError] = $this->lease((string) ($row['contract'] ?? ''));
            if ($contractError !== null) {
                $errors["rows.$i.contract"] = [$contractError];
            }
            try {
                $this->payments->validate($data);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $errors["rows.$i.$field"] = $messages;
                }
            }
            $valid[] = [$lease, $data];
        }

        if ($valid === [] && $errors === []) {
            $errors['rows'] = [__('payments.batch.errors.empty')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(fn () => array_map(fn ($pair) => $this->payments->record($pair[0], $pair[1]), $valid));
    }

    /** Полето на реда, ако е попълнено; иначе общото. */
    private function pick(array $row, array $common, string $field): mixed
    {
        $value = $row[$field] ?? null;

        return $value === null || trim((string) $value) === '' ? ($common[$field] ?? null) : $value;
    }

    /** @return array{0: ?LeaseContract, 1: ?string} договорът или грешката */
    private function lease(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [null, __('payments.batch.errors.contract_required')];
        }
        if (preg_match('/^(\d+)/u', $text, $m)) {
            $lease = LeaseContract::query()->whereNull('archived_at')->where('number', (int) $m[1])->first();

            return $lease ? [$lease, null] : [null, __('payments.batch.errors.contract_not_found')];
        }
        $leases = LeaseContract::query()->whereNull('archived_at')->whereHas('tenant', fn ($q) => $q->where('name', $text))->get();

        return match ($leases->count()) {
            1 => [$leases->first(), null],
            0 => [null, __('payments.batch.errors.contract_not_found')],
            default => [null, __('payments.batch.errors.ambiguous')],
        };
    }
}
