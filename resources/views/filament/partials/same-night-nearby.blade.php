<ul>
    @foreach ($items as $item)
        <li>
            <a href="{{ $item['url'] }}" target="_blank" rel="noopener"><strong>{{ $item['title'] }}</strong></a>
            · {{ $item['time'] }} · {{ $item['place'] }}
            · {{ __('manage.same_night.distance', ['m' => $item['distance_m']]) }}
            @if ($item['category'] !== '')
                · {{ $item['same_category'] ? __('manage.same_night.same_category', ['category' => $item['category']]) : $item['category'] }}
            @endif
        </li>
    @endforeach
</ul>
