{{--
    Phone, email and address, from the profile.

    No embedded map, deliberately. An iframe or a map SDK is a third-party
    request made by every anonymous visitor, and this page's CSP is
    `default-src 'none'` with no `frame-src` and no `script-src` at all. The
    address is text; a map would cost the page its best security property.
--}}
<dl class="contact">
    @if ($page->contactPhone)
        <dt>{{ __('public.phone') }}</dt>
        <dd><a href="tel:{{ $page->contactPhone }}">{{ $page->contactPhone }}</a></dd>
    @endif

    @if ($page->contactEmail)
        <dt>{{ __('public.email') }}</dt>
        <dd><a href="mailto:{{ $page->contactEmail }}">{{ $page->contactEmail }}</a></dd>
    @endif

    @if ($page->formattedAddress())
        <dt>{{ __('public.address') }}</dt>
        <dd>{{ $page->formattedAddress() }}</dd>
    @endif
</dl>
