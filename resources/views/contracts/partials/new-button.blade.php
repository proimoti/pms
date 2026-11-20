{{-- „+ Нов“ до избора: отваря обикновената форма в малък прозорец (PROJECT.md, 20.11.2026).
     $section: properties, owners или tenants. Поведението е в contracts/partials/quick-new. --}}
<button type="button" class="secondary quick-new-button" data-quick-new="/{{ $section }}/create?popup=1"
        aria-label="{{ __('registry.new.'.$section) }}" title="{{ __('registry.new.'.$section) }}">{{ __('registry.quick_new') }}</button>
