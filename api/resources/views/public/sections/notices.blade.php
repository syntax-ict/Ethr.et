{{--
    Public notices, typed here by an administrator.

    These are NOT the `announcements` table. That table has no public
    visibility flag and every row in it is internal HR content, so joining it
    to this page would be the single most damaging thing this feature could do.
    NoticesAreNotAnnouncementsTest asserts an existing announcement never
    appears here.
--}}
<ul class="notices">
    @foreach ($section->items as $item)
        <li class="notice">
            @if ($item->title !== '')
                <h3 class="notice__title">{{ $item->title }}</h3>
            @endif

            @if ($item->dateIso)
                {{-- The label honours the tenant's calendar; the datetime
                     attribute stays ISO-8601 so machines read it unambiguously. --}}
                <p class="notice__date">
                    <time datetime="{{ $item->dateIso }}">{{ $item->dateLabel }}</time>
                </p>
            @endif

            @foreach ($item->paragraphs() as $paragraph)
                <p class="notice__body">{{ $paragraph }}</p>
            @endforeach

            @if ($item->linkUrl)
                <a href="{{ $item->linkUrl }}" rel="noopener noreferrer nofollow">
                    {{ $item->linkLabel !== '' ? $item->linkLabel : __('public.read_more') }}
                </a>
            @endif
        </li>
    @endforeach
</ul>
