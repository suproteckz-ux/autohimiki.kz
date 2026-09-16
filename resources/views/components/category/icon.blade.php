@props(['slug'])
@php
    $icon = in_array($slug, [
        'amsoil-masla',
        'novye-tovary',
        'eikosha-aromatizatory',
        'avtokosmetika',
        'aksessuary-dlya-moyki-i-himchistki-avto',
        'antigel-dlya-dizelnogo-topliva',
        'zhidkost-v-bochek-omyvatelya-nezamerzayka',
        'ochistiteli-kontaktov',
        'ochistitel-dmrv',
        'preobrazovateli-rzhavchiny-movili',
        'prisadki',
        'raskoksovka-dvigatelya',
        'smazki-dlya-avto',
    ], true) ? $slug : '_fallback';
@endphp
<svg {{ $attributes->class(['ah-category-icon']) }} viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($icon)
        @case('amsoil-masla')
            <path d="M8 9h10v16H8zM11 6h4v3M18 13h4l2 2v7h-6M24 16h2v4h-2"/><path d="M11 13h4M11 17h4"/>
            @break
        @case('novye-tovary')
            <path d="M16 4v24M4 16h24M9 9l14 14M23 9 9 23"/><circle cx="16" cy="16" r="3"/>
            @break
        @case('eikosha-aromatizatory')
            <path d="M16 4 10 25h12L16 4Z"/><path d="M12.5 18h7M14 13h4M13 25v3h6v-3"/>
            @break
        @case('avtokosmetika')
            <path d="M11 10h10l2 5v12H9V15l2-5Z"/><path d="M13 6h6v4M12 18h8M24 8v5M21.5 10.5h5"/>
            @break
        @case('aksessuary-dlya-moyki-i-himchistki-avto')
            <path d="M5 13h12v6H5zM17 14l7-4v12l-7-4M10 19v6M7 25h6"/><path d="M26 12l2-2M26 16h3M26 20l2 2"/>
            @break
        @case('antigel-dlya-dizelnogo-topliva')
            <path d="M8 10h10v16H8zM11 7h4v3M18 14h4v12h-4"/><path d="M25 5v7M21.5 8.5h7M22.5 6l5 5M27.5 6l-5 5"/>
            @break
        @case('zhidkost-v-bochek-omyvatelya-nezamerzayka')
            <path d="M5 19c2-5 6-8 11-8s9 3 11 8H5Z"/><path d="M8 19v5M12 19v3M16 19v5M20 19v3M24 19v5M12 7l-2-3M20 7l2-3"/>
            @break
        @case('ochistiteli-kontaktov')
            <path d="M11 5v8M21 5v8M8 13h16v4a8 8 0 0 1-16 0v-4Z"/><path d="M16 25v4M25 8l3-2M25 12h3"/>
            @break
        @case('ochistitel-dmrv')
            <path d="M10 7v18M14 10h8v12h-8M22 13h4M22 19h4"/><path d="M7 11H4M7 16H3M7 21H4"/>
            @break
        @case('preobrazovateli-rzhavchiny-movili')
            <path d="m7 24 16-16 3 3-16 16H7v-3Z"/><path d="m19 12 3 3M5 27h22M9 19l4 4"/>
            @break
        @case('prisadki')
            <path d="M7 7h7v5l-2 3v11H6V15l2-3V7ZM19 10h7v16h-7zM21 6h3v4"/><path d="M9 18h3M21 17h3"/>
            @break
        @case('raskoksovka-dvigatelya')
            <path d="M6 13h4l3-4h8l3 4h3v11H6V13Z"/><path d="M10 17h4M18 17h5M9 24v3M24 24v3M14 9V6h5v3"/>
            @break
        @case('smazki-dlya-avto')
            <path d="M11 8h9v19h-9zM13 5h5v3M20 11h4v5h-4"/><path d="M14 14h3M14 18h3M24 8l3-2M25 12h3"/>
            @break
        @default
            <path d="M7 8h18v16H7zM11 12h10M11 16h7M11 20h5"/><circle cx="24" cy="8" r="3"/>
    @endswitch
</svg>
