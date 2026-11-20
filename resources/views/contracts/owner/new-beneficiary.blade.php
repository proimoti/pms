{{-- „+ Нов бенефициент“ направо от формата: полетата нямат name, затова не се
     изпращат с договора; записват се отделно и новият бенефициент се избира в
     списъка $target, без да се губи въведеното. IBAN само с правото. --}}
<div class="new-beneficiary" data-target="{{ $target }}">
    <button type="button" class="secondary" data-open style="margin-top: 8px;">{{ __('contracts.screens.new_beneficiary') }}</button>
    <div data-panel hidden style="border: 1px solid var(--line); border-radius: 6px; padding: 12px; margin-top: 8px;">
        <label for="new-beneficiary-name">{{ __('registry.fields.name') }} *</label>
        <input id="new-beneficiary-name" type="text" data-field="name" autocomplete="off">
        @if (auth()->user()->can_view_sensitive)
            <label for="new-beneficiary-iban">{{ __('registry.fields.iban') }}</label>
            <input id="new-beneficiary-iban" type="text" data-field="iban" autocomplete="off">
            <div class="muted">{{ __('registry.iban_hint') }}</div>
        @endif
        <div class="errors" data-errors hidden style="margin-top: 10px;"></div>
        <div class="actions">
            <button type="button" data-save>{{ __('contracts.screens.create_beneficiary') }}</button>
            <button type="button" class="secondary" data-close>{{ __('registry.cancel') }}</button>
        </div>
    </div>
</div>

@once
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.new-beneficiary').forEach(function (box) {
                const panel = box.querySelector('[data-panel]');
                const errors = box.querySelector('[data-errors]');
                const select = document.getElementById(box.dataset.target);

                box.querySelector('[data-open]').addEventListener('click', function () {
                    panel.hidden = false;
                    box.querySelector('[data-field="name"]').focus();
                });
                box.querySelector('[data-close]').addEventListener('click', function () { panel.hidden = true; });
                // Enter в тези полета създава бенефициента, а не изпраща договора.
                box.querySelectorAll('[data-field]').forEach(function (input) {
                    input.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            box.querySelector('[data-save]').click();
                        }
                    });
                });

                box.querySelector('[data-save]').addEventListener('click', async function () {
                    const body = {};
                    box.querySelectorAll('[data-field]').forEach(function (input) { body[input.dataset.field] = input.value; });
                    errors.hidden = true;

                    const response = await fetch('/beneficiaries', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
                        body: JSON.stringify(body),
                    });
                    const data = await response.json().catch(function () { return {}; });

                    if (!response.ok) {
                        const messages = data.errors ? Object.values(data.errors).flat() : [data.message || @js(__('contracts.screens.new_beneficiary_failed'))];
                        errors.textContent = messages.join(' ');
                        errors.hidden = false;
                        return;
                    }

                    const option = new Option(data.name, data.id, true, true);
                    select.add(option);
                    select.value = String(data.id);
                    box.querySelectorAll('[data-field]').forEach(function (input) { input.value = ''; });
                    panel.hidden = true;
                });
            });
        });
    </script>
@endonce
