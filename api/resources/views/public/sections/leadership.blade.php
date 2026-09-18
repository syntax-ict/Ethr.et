{{--
    Named office-holders an organisation publishes deliberately.

    These are typed into the builder, not read from `employees`. A page that
    pulled its leadership from the HR system would be one schema change away
    from publishing someone who had not agreed to be published.
--}}
<ul class="people">
    @foreach ($section->items as $item)
        <li class="person">
            @if ($item->imageUrl)
                <img class="person__photo" src="{{ $item->imageUrl }}" alt="{{ $item->imageAlt }}"
                     loading="lazy" decoding="async">
            @endif

            @if ($item->title !== '')
                <h3 class="person__name">{{ $item->title }}</h3>
            @endif

            @isset($item->meta['role'])
                <p class="person__role">{{ $item->meta['role'] }}</p>
            @endisset

            @foreach ($item->paragraphs() as $paragraph)
                <p class="person__bio">{{ $paragraph }}</p>
            @endforeach
        </li>
    @endforeach
</ul>
