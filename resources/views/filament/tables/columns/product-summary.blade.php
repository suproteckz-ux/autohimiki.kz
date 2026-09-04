@php
    $product = $getRecord();
    $placeholder = 'data:image/svg+xml;base64,' . base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><rect width="40" height="40" rx="6" fill="#f3f4f6"/><path d="M12 27l5-6 4 4 3-3 5 5H12zm5-10a2 2 0 110-4 2 2 0 010 4z" fill="#9ca3af"/></svg>'
    );
    $thumbnail = $product->main_image
        ? asset('storage/' . $product->main_image)
        : $placeholder;
@endphp

<div class="fi-product-summary" style="display:flex;align-items:center;gap:.625rem;min-width:20rem;max-width:100%">
    <img
        src="{{ $thumbnail }}"
        alt=""
        width="40"
        height="40"
        loading="lazy"
        style="width:40px;height:40px;flex:0 0 40px;border-radius:.375rem;object-fit:contain;background:#f3f4f6"
    >
    <div style="min-width:0;flex:1">
        <div title="{{ $product->name }}" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500">
            {{ $product->name }}
        </div>
        <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#6b7280;font-size:.75rem;line-height:1rem">
            {{ $product->sku }}
        </div>
    </div>
</div>
