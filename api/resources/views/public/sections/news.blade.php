{{--
    News and updates, as image-led cards.

    Deliberately not the notices list. A notice is a statement of record that
    someone came looking for; news is read by a visitor who arrived for another
    reason and stayed, so it leads with a picture, carries an excerpt rather
    than the whole item, and links out to the full story.

    <article> rather than <li>: each entry is independently meaningful, which is
    what the element means and what a screen reader's article navigation relies
    on. The date is a <time> with an ISO attribute, so the Ethiopian calendar
    label a reader sees does not cost a machine the unambiguous value.
--}}
<div class="news {{ $section->layoutClass() }}">
    @foreach ($section->items as $item)
        <article class="news__item">
            @if ($item->imageUrl)
                <img class="news__image" src="{{ $item->imageUrl }}" alt="{{ $item->imageAlt }}"
                     loading="lazy" decoding="async">
            @endif

            <div class="news__body">
                @if ($item->dateIso)
                    <p class="news__date">
                        <time datetime="{{ $item->dateIso }}">{{ $item->dateLabel }}</time>
                    </p>
                @endif

                @if ($item->title !== '')
                    <h3 class="news__title">{{ $item->title }}</h3>
                @endif

                @foreach ($item->paragraphs() as $paragraph)
                    <p class="news__excerpt">{{ $paragraph }}</p>
                @endforeach

                @if ($item->linkUrl)
                    {{--
                        `nofollow` alongside noopener/noreferrer, as everywhere
                        else a tenant can put a URL: these links are typed by
                        tenants, and this page should not lend them ranking.
                    --}}
                    <a class="news__link" href="{{ $item->linkUrl }}" rel="noopener noreferrer nofollow">
                        {{ $item->linkLabel !== '' ? $item->linkLabel : __('public.read_more') }}
                    </a>
                @endif
            </div>
        </article>
    @endforeach
</div>
