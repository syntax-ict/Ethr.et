{{--
    Photographs. Every one carries alternative text, which the upload endpoint
    requires — an image with none is a WCAG 1.1.1 failure, and on a gallery it
    would be the whole section that is invisible to a screen reader.
--}}
<ul class="gallery">
    @foreach ($section->items as $item)
        @if ($item->imageUrl)
            <li class="gallery__item">
                <img src="{{ $item->imageUrl }}" alt="{{ $item->imageAlt }}"
                     loading="lazy" decoding="async">
                @if ($item->title !== '')
                    <p class="gallery__caption">{{ $item->title }}</p>
                @endif
            </li>
        @endif
    @endforeach
</ul>
