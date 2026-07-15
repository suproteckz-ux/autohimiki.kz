<article class="rounded border border-gray-200 bg-white p-4">
    <a href="{{ $product->url }}" class="font-semibold text-gray-900 hover:text-primary-600">
        {{ $product->name }}
    </a>

    @if(isset($product->price))
        <div class="mt-2 text-sm text-gray-600">
            {{ number_format((float) $product->price, 0, '.', ' ') }} KZT
        </div>
    @endif
</article>
