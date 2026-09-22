{{--
    One icon, referenced from the sprite in the layout.

    `$name` has passed an allow-list twice — once in the FormRequest and again
    in PublicSectionItem — because it is interpolated into a URL fragment here.
    An escaped-but-arbitrary string would still be a string in an href.
--}}
<svg class="icon" aria-hidden="true" focusable="false" width="24" height="24">
    <use href="#icon-{{ $name }}"></use>
</svg>
