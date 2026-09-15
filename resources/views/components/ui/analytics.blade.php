@php
    $metrikaId = (string) config('services.yandex_metrika.counter_id', '');
@endphp

@if(preg_match('/^[1-9][0-9]{0,14}$/D', $metrikaId))
    <!-- Yandex.Metrika counter -->
    <script>
        (function(m,e,t,r,i,k,a){
            m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
            m[i].l=1*new Date();
            for (var j=0;j<document.scripts.length;j++) {
                if (document.scripts[j].src===r) { return; }
            }
            k=e.createElement(t),a=e.getElementsByTagName(t)[0];
            k.async=1;k.src=r;a.parentNode.insertBefore(k,a);
        })(window,document,'script','https://mc.yandex.ru/metrika/tag.js?id={{ $metrikaId }}','ym');

        ym({{ $metrikaId }}, 'init', {
            ssr: true,
            webvisor: true,
            ecommerce: 'dataLayer',
            referrer: document.referrer,
            url: location.href,
            clickmap: true,
            trackLinks: true,
            accurateTrackBounce: true
        });
    </script>
    <noscript><div><img src="https://mc.yandex.ru/watch/{{ $metrikaId }}" style="position:absolute;left:-9999px" alt=""></div></noscript>
    <!-- /Yandex.Metrika counter -->
@endif
