{{-- What the organisation does, as cards. --}}
<div class="cards {{ $section->layoutClass() }}">
    @foreach ($section->items as $item)
        <article class="card">
            @if ($item->icon)
                @include('public.partials.icon', ['name' => $item->icon])
            @endif

            @if ($item->title !== '')
                <h3 class="card__title">{{ $item->title }}</h3>
            @endif

            @foreach ($item->paragraphs() as $paragraph)
                <p class="card__body">{{ $paragraph }}</p>
            @endforeach

            @if ($item->imageUrl)
                <img class="card__image" src="{{ $item->imageUrl }}" alt="{{ $item->imageAlt }}"
                     loading="lazy" decoding="async">
            @endif

            @if ($item->linkUrl)
                <a class="card__link" href="{{ $item->linkUrl }}" rel="noopener noreferrer nofollow">
                    {{ $item->linkLabel !== '' ? $item->linkLabel : __('public.read_more') }}
                </a>
            @endif
        </article>
    @endforeach
</div>
