@props(['title', 'href' => null, 'link' => 'Смотреть все'])
<div class="ah-section-heading"><h2>{{ $title }}</h2>@if($href)<a href="{{ $href }}">{{ $link }} <span aria-hidden="true">→</span></a>@endif</div>
