<form action="{{ route('search') }}" method="GET" class="ah-search" role="search" aria-label="Поиск по каталогу">
    <label class="sr-only" for="storefront-search">Название товара, бренд или артикул</label>
    <input id="storefront-search" type="search" name="q" value="{{ request('q') }}" placeholder="Поиск по названию, бренду или артикулу…">
    <button type="submit">Найти <span aria-hidden="true">→</span></button>
</form>
