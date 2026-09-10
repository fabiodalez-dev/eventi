{{--
    Feed RSS (§11.10).

    Nessun layout, nessuno spazio prima della dichiarazione XML: un solo
    carattere fuori posto e i lettori di feed rifiutano il documento.

    Le date sono in RFC 2822 con lo scarto del fuso, come vuole la specifica
    RSS 2.0. Il testo **non** sta dentro CDATA: passa dall'escape di Blade, che
    trasforma la e commerciale di "Concerto & sagra" in `&amp;` e produce un
    documento valido. CDATA sarebbe più fragile, non meno: basta un `]]>` in un
    titolo per spezzarlo, e nessuno se ne accorgerebbe fino al primo lettore che
    rifiuta il feed. L'HTML dentro `description` resta quindi codificato, che è
    il modo in cui RSS lo trasporta da sempre.
--}}
@php
    $formatter = app(\App\Support\DateFormatter::class);
    $app = config('app.name');
@endphp
{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ $title }} — {{ $app }}</title>
        <link>{{ $link }}</link>
        <atom:link href="{{ $self }}" rel="self" type="application/rss+xml"/>
        <description>{{ __('feeds.rss.description', ['city' => $city->name]) }}</description>
        <language>{{ str_replace('_', '-', app()->getLocale()) }}</language>
        <lastBuildDate>{{ now()->toRfc2822String() }}</lastBuildDate>
        @foreach ($occurrences as $occurrence)
            @php
                $event = clone $occurrence->event;
                $event->setRelation('venue', $occurrence->effectiveVenue());
                $url = \App\Support\EventUrl::occurrence($occurrence);
                $poster = \App\Support\Poster::absoluteUrl($event);

                /* In un feed la data va scritta per esteso: "oggi" è vero solo
                   nell'istante in cui il file viene generato, e un lettore di
                   feed lo rilegge tre giorni dopo. */
                $when = $occurrence->is_all_day
                    ? $formatter->weekdayDate($occurrence->business_date)
                    : __('dates.day_at_time', [
                        'date' => $formatter->weekdayDate($occurrence->business_date),
                        'time' => $formatter->time($occurrence->starts_at),
                    ]);
            @endphp
            <item>
                <title>{{ $event->title }} — {{ $when }}</title>
                <link>{{ $url }}</link>
                <guid isPermaLink="false">occorrenza-{{ $occurrence->getKey() }}</guid>
                <pubDate>{{ $occurrence->starts_at->toRfc2822String() }}</pubDate>
                @if ($event->category !== null)
                    <category>{{ $event->category->name }}</category>
                @endif
                <description>{{ $event->short_description ?? $event->subtitle ?? '' }}@if ($event->venue !== null)

{{ $event->venue->name }}, {{ $event->venue->municipality }}@endif
@if ($poster !== null)

{{ '<img src="'.$poster.'" alt="">' }}@endif</description>
            </item>
        @endforeach
    </channel>
</rss>
