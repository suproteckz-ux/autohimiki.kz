@props(['items' => []])

<nav class="text-sm text-gray-500" aria-label="Breadcrumbs">
    @foreach($items as $item)
        @if(! $loop->first)
            <span class="mx-2">/</span>
        @endif

        @if(!empty($item['url']))
            <a href="{{ $item['url'] }}" class="hover:text-gray-900">{{ $item['name'] }}</a>
        @else
            <span>{{ $item['name'] }}</span>
        @endif
    @endforeach
</nav>
