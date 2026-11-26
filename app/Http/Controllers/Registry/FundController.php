<?php

namespace App\Http\Controllers\Registry;

use App\Models\Fund;
use App\Models\Property;
use App\Models\UtilityAccount;
use App\Services\Charges\Month;
use App\Services\Utilities\FundLedger;
use App\Services\Utilities\FundService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Екранът „Общи фондове“ и справката за фонда (PROJECT.md, „Общ фонд“,
 * решено 22.11.2026, уточнено 23.11.2026): събрано, платено и остатък за
 * период и общо, от книгата.
 */
class FundController extends RegistryController
{
    public function __construct(
        private readonly FundService $funds,
        private readonly FundLedger $ledger,
    ) {}

    protected function section(): string
    {
        return 'funds';
    }

    /** По име, по аналитика или по номер на имот. */
    protected function applySearch(Builder $query, string $term, string $likeTerm): void
    {
        if (ctype_digit($term)) {
            $query->orWhere('number', (int) $term);
        }
        $query->orWhere('name', 'like', $likeTerm);

        if (ctype_digit($term)) {
            $query->orWhereHas('properties.property', fn (Builder $property) => $property->where('number', (int) $term));
        }
    }

    public function index(Request $request): View
    {
        $query = $this->listQuery($request, Fund::query()->with('properties.property')->orderBy('name'));

        return view('registry.funds.index', ['funds' => $this->paginate($query)]);
    }

    public function create(): View
    {
        return $this->form(new Fund, []);
    }

    public function store(Request $request): RedirectResponse
    {
        $fund = $this->funds->create($this->input($request));

        return redirect("/funds/{$fund->id}")->with('status', __('registry.saved'));
    }

    /** Картата на фонда със справката. Периодът по подразбиране е текущата година. */
    public function show(Request $request, Fund $fund): View
    {
        $from = $this->month($request, 'from') ?? CarbonImmutable::today()->startOfYear();
        $to = $this->month($request, 'to') ?? CarbonImmutable::today()->startOfMonth();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $fund->load('properties.property');

        return view('registry.funds.show', [
            'fund' => $fund,
            'from' => $from,
            'to' => $to,
            'period' => $this->ledger->report($fund, $from, $to),
            'total' => $this->ledger->report($fund),
            'entries' => $this->ledger->entries($fund, $from, $to)->with('paidUtilityBill')->get(),
            'accounts' => UtilityAccount::query()->where('fund_id', $fund->id)->with('provider')->orderBy('archived_at')->orderBy('id')->get(),
        ]);
    }

    public function edit(Fund $fund): View
    {
        return $this->form($fund, $fund->propertyIds());
    }

    public function update(Request $request, Fund $fund): RedirectResponse
    {
        $this->funds->update($fund, $this->input($request));

        return redirect("/funds/{$fund->id}")->with('status', __('registry.saved'));
    }

    public function archive(Fund $fund): RedirectResponse
    {
        return $this->archiveRecord($fund);
    }

    public function restore(Fund $fund): RedirectResponse
    {
        return $this->restoreRecord($fund);
    }

    /** @param list<int> $selected */
    private function form(Fund $fund, array $selected): View
    {
        $selected = array_map('intval', (array) old('properties', $selected));

        return view('registry.funds.form', [
            'fund' => $fund,
            'selected' => $selected,
            // Активните имоти и вече избраните (ако някой е архивиран, да се вижда).
            'properties' => Property::query()->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $selected))->orderBy('number')->get(),
        ]);
    }

    private function input(Request $request): array
    {
        return $request->only(['name', 'number', 'note']) + ['properties' => (array) $request->input('properties', [])];
    }

    /** Месец от адреса („12.2026“ или „2026-12“); празно или грешно = няма. */
    private function month(Request $request, string $key): ?CarbonImmutable
    {
        $value = $request->query($key);

        return is_string($value) ? Month::tryParse($value) : null;
    }
}
