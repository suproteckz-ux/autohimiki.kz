<form action="{{ route('search') }}" method="GET" class="ah-search" role="search" aria-label="Поиск по каталогу">
    <a class="ah-search-catalog" href="{{ route('catalog') }}">Все категории <span aria-hidden="true">⌄</span></a>
    <label class="sr-only" for="storefront-search">Название товара, бренд или артикул</label>
    <input id="storefront-search" type="search" name="q" value="{{ request('q') }}" placeholder="Название товара, бренд или артикул">
    <button type="submit">Найти <span aria-hidden="true">→</span></button>
</form>
