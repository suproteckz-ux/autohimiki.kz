@props(['product'])

@if($product->is_active && filled($product->sku) && filled(config('services.kaspi.merchant_id')) && filled(config('services.kaspi.city_id')))
    @once
        @push('scripts')
            <script>
                (function(d, s, id) {
                    if (d.getElementById(id)) return;
                    var js = d.createElement(s);
                    js.id = id;
                    js.src = {{ Illuminate\Support\Js::from(config('services.kaspi.widget_script_url')) }};
                    js.async = true;
                    d.body.appendChild(js);
                }(document, 'script', 'KS-Widget'));
            </script>
        @endpush
    @endonce

    <div class="mb-6">
        <div class="ks-widget"
             data-template="{{ config('services.kaspi.button_template') }}"
             data-merchant-sku="{{ $product->sku }}"
             data-merchant-code="{{ config('services.kaspi.merchant_id') }}"
             data-city="{{ config('services.kaspi.city_id') }}"
             data-style="desktop"></div>
    </div>
@endif
