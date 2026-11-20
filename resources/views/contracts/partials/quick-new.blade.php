{{-- Малкият прозорец за „+ Нов“ (PROJECT.md, 20.11.2026): обикновената форма
     за имот, собственик или наемател в рамка. След запис формата в рамката
     казва кой е новият запис (registry.partials.quick-saved); той се добавя
     във всички списъци от този вид (и в образеца за нови редове) и се избира
     в списъка до бутона. Формата на договора не се презарежда. --}}
<dialog id="quick-new" class="quick-new" aria-label="{{ __('registry.quick_new_title') }}">
    <div class="quick-new-bar">
        <button type="button" class="secondary" data-quick-close>{{ __('registry.close') }}</button>
    </div>
    <iframe title="{{ __('registry.quick_new_title') }}" src="about:blank"></iframe>
</dialog>

<script>
    (function () {
        const dialog = document.getElementById('quick-new');
        const frame = dialog.querySelector('iframe');
        let target = null;

        function reset() {
            frame.src = 'about:blank';
            target = null;
        }

        function close() {
            if (dialog.open) {
                dialog.close();
            }
            reset();
        }

        // Затваряне с Esc.
        dialog.addEventListener('close', reset);

        document.addEventListener('click', function (event) {
            const button = event.target.closest('[data-quick-new]');
            if (button) {
                event.preventDefault();
                target = button.closest('.with-new').querySelector('select');
                frame.src = button.dataset.quickNew;
                dialog.showModal();
                return;
            }
            if (event.target.closest('[data-quick-close]')) {
                close();
            }
        });

        window.addEventListener('message', function (event) {
            if (event.origin !== window.location.origin || event.source !== frame.contentWindow || !event.data) {
                return;
            }
            if (event.data.type === 'pms-quick-new-cancel') {
                close();
                return;
            }
            if (event.data.type !== 'pms-quick-new') {
                return;
            }

            const selector = 'select[data-quick-section="' + event.data.section + '"]';
            const selects = Array.from(document.querySelectorAll(selector));
            document.querySelectorAll('template').forEach(function (template) {
                selects.push.apply(selects, Array.from(template.content.querySelectorAll(selector)));
            });
            selects.forEach(function (select) {
                select.add(new Option(event.data.label, String(event.data.id)));
            });

            if (target) {
                target.value = String(event.data.id);
                target.dispatchEvent(new Event('change', { bubbles: true }));
                target.focus();
            }
            close();
        });
    })();
</script>
