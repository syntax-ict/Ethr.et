{{--
    The tenant's public landing page — preset layout.

    Rendered when `tenant_public_profiles.preset` is set. A tenant that has not
    opted in still gets `landing.blade.php`, unchanged, which is why that file
    is left alone rather than refactored: its output is pinned byte-for-byte by
    a snapshot test, and the safest way to keep a guarantee like that is not to
    touch the file it is about.

    The two templates share markup today and diverge once sections land. The
    preset reaches this page as a body class from the controller, not as a
    branch in here: a template that asks "which preset am I?" in a dozen places
    is a template that gets one of them wrong.

    `$page` is an App\Support\PublicTenantPage — never a Tenant or a
    TenantPublicProfile. Everything this template can reach was chosen by that
    class, so a field cannot be leaked here by accident; adding one to the page
    means adding it there first.

    No inline PHP block, by rule. Derived values (the theme attribute, the
    formatted address) are methods on the view-model instead. See the "no
    unescaped echo in any public view" test, which enforces both halves.
--}}
@extends('public.layout')

@section('title', $page->headline ? "{$page->name} — {$page->headline}" : $page->name)

@if ($page->metaDescription)
    @section('description', $page->metaDescription)
@endif

@section('meta')
    {{--
        Both language variants, declared to crawlers.

        The page has always been bilingual — SetPublicLocale honours `?lang`
        and the header offers the switch — but nothing told a search engine the
        other version existed, so only whichever one happened to be crawled
        could ever rank. `x-default` is the unsuffixed URL, which serves the
        tenant's own default locale.

        Declared here rather than in the shared layout on purpose: the layout
        is also rendered by the classic page, and adding a block there changed
        that page's bytes even when the variable was unset, because Blade keeps
        the newlines around a directive it did not emit. The snapshot test
        caught it. Only the page that has alternates should talk about them.
    --}}
    @foreach ($alternates as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
    @endforeach

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $page->name }}">
    <meta property="og:title" content="{{ $page->name }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:locale" content="{{ app()->getLocale() === 'am' ? 'am_ET' : 'en_US' }}">
    @if ($page->metaDescription)
        <meta property="og:description" content="{{ $page->metaDescription }}">
    @endif
    @if ($page->hasHero)
        <meta property="og:image" content="{{ url('/media/hero') }}">
    @elseif ($page->hasLogo)
        <meta property="og:image" content="{{ url('/media/logo') }}">
    @endif

    <meta name="twitter:card" content="{{ $page->hasHero ? 'summary_large_image' : 'summary' }}">

    {{--
        JSON-LD, built in PublicTenantPage::jsonLd() from the same
        allow-listed data as the visible page.

        Two plain variables rather than the array literal that used to be here:
        Blade matches a directive's brackets to find its arguments, and a
        multi-line array followed by a second argument defeated that matcher —
        the view failed to compile at all. See the note on jsonLd().

        `@json` is unescaped output, and this is the only unescaped output on
        the public surface. It emits machine-generated JSON, never tenant text
        verbatim, and PublicTenantPage::JSON_LD_FLAGS carries the JSON_HEX_TAG
        that stops a `</script>` in a tenant field from ending this element.
    --}}
    <script type="application/ld+json">
        @json($jsonLd, $jsonLdFlags)
    </script>
@endsection

@section('body')
    {{--
        The icon sprite.

        One inline SVG for every icon the builder offers, defined once and
        referenced by `<use href="#icon-name">`. Not an icon font — this page
        deliberately makes no webfont request — and not a file per icon, which
        would be a network request each on a page whose whole point is to load
        fast on an Ethiopian connection. External URLs are not an option at
        all: the CSP is `default-src 'none'` with `img-src 'self'`.

        Hidden from assistive technology and removed from the layout: it is a
        definition, not content. Every icon that uses it is itself
        aria-hidden, because an icon beside a heading repeats the heading.

        IconAllowListTest keeps these symbols and PublicSectionIcons::NAMES in
        step, in both directions.
    --}}
    <svg xmlns="http://www.w3.org/2000/svg" hidden aria-hidden="true" class="icon-sprite">
        <symbol id="icon-briefcase" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></symbol>
        <symbol id="icon-clipboard" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9z"/></symbol>
        <symbol id="icon-file-text" viewBox="0 0 24 24"><path d="M6 2h8l4 4v16H6z"/><path d="M9 12h6M9 16h6"/></symbol>
        <symbol id="icon-stamp" viewBox="0 0 24 24"><path d="M5 20h14"/><path d="M8 16v-3a4 4 0 1 1 8 0v3"/><rect x="5" y="16" width="14" height="3"/></symbol>
        <symbol id="icon-scale" viewBox="0 0 24 24"><path d="M12 3v18M5 7h14"/><path d="M5 7 2 14h6zM19 7l-3 7h6z"/></symbol>
        <symbol id="icon-shield" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-4 8-8 9-4-1-8-4-8-9V6z"/></symbol>
        <symbol id="icon-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 11a3 3 0 1 0 0-6"/></symbol>
        <symbol id="icon-user-tie" viewBox="0 0 24 24"><circle cx="12" cy="7" r="3"/><path d="M6 21a6 6 0 0 1 12 0"/><path d="M12 11l-1 4h2z"/></symbol>
        <symbol id="icon-building" viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2"/></symbol>
        <symbol id="icon-landmark" viewBox="0 0 24 24"><path d="M3 21h18M5 21V10M19 21V10M9 21V10M15 21V10"/><path d="M12 3l9 5H3z"/></symbol>
        <symbol id="icon-map-pin" viewBox="0 0 24 24"><path d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></symbol>
        <symbol id="icon-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/></symbol>
        <symbol id="icon-phone" viewBox="0 0 24 24"><path d="M6 3h4l2 5-3 2a12 12 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2z"/></symbol>
        <symbol id="icon-mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></symbol>
        <symbol id="icon-message" viewBox="0 0 24 24"><path d="M4 5h16v11H9l-5 4z"/></symbol>
        <symbol id="icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
        <symbol id="icon-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></symbol>
        <symbol id="icon-graduation-cap" viewBox="0 0 24 24"><path d="m12 4 10 5-10 5L2 9z"/><path d="M6 11v5c0 1.5 3 3 6 3s6-1.5 6-3v-5"/></symbol>
        <symbol id="icon-stethoscope" viewBox="0 0 24 24"><path d="M6 3v6a4 4 0 0 0 8 0V3"/><path d="M10 13v3a5 5 0 0 0 9 3"/><circle cx="19" cy="17" r="2"/></symbol>
        <symbol id="icon-bank" viewBox="0 0 24 24"><path d="M3 21h18M5 21V10M19 21V10M9 21V10M15 21V10"/><path d="M12 3l9 5H3z"/></symbol>
        <symbol id="icon-factory" viewBox="0 0 24 24"><path d="M3 21V11l6 4V11l6 4V7l6 4v10z"/></symbol>
        <symbol id="icon-bed" viewBox="0 0 24 24"><path d="M3 20v-9h18v9M3 15h18"/><circle cx="8" cy="11" r="2"/></symbol>
        <symbol id="icon-leaf" viewBox="0 0 24 24"><path d="M4 20C4 10 10 4 20 4c0 10-6 16-16 16z"/><path d="M4 20 14 10"/></symbol>
        <symbol id="icon-star" viewBox="0 0 24 24"><path d="m12 3 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.8l6.5-.9z"/></symbol>
        <symbol id="icon-check" viewBox="0 0 24 24"><path d="m4 12 5 5L20 6"/></symbol>
        <symbol id="icon-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
    </svg>

    <header class="site-header">
        <div class="shell site-header__inner">
            <div class="brand">
                @if ($page->hasLogo)
                    <img class="brand__logo" src="{{ url('/media/logo') }}"
                         alt="{{ __('public.logo_alt', ['organization' => $page->name]) }}"
                         width="40" height="40">
                @endif
                <span class="brand__name">{{ $page->name }}</span>
            </div>

            <nav class="site-nav" aria-label="{{ __('public.nav_label') }}">
                <a class="lang-switch"
                   href="?lang={{ app()->getLocale() === 'am' ? 'en' : 'am' }}"
                   lang="{{ app()->getLocale() === 'am' ? 'en' : 'am' }}">
                    {{ app()->getLocale() === 'am' ? 'English' : 'አማርኛ' }}
                </a>
                <a class="btn btn--primary" href="/login">{{ __('public.employee_sign_in') }}</a>
            </nav>
        </div>
    </header>

    <main id="main">
        {{--
            The page body, one block per section the tenant has chosen.

            The hero renders outside the generic wrapper because it owns the
            page's only <h1> and its own full-bleed layout; every other kind
            gets the same <section> shell with a heading and an
            aria-labelledby that points at it, so the document outline is
            consistent whatever an administrator has assembled.

            Sections with no content were dropped before they reached this
            template — a visible block whose items were all deleted would
            otherwise render as a heading over nothing, which reads as broken.
        --}}
        @foreach ($page->sections as $index => $section)
            @if ($section->kind === $heroKind)
                @include('public.sections.hero')

                @include('public.partials.tibeb')
            @else
                <section class="section" aria-labelledby="section-{{ $index }}">
                    <div class="shell">
                        @include('public.sections._heading', [
                            'section' => $section,
                            'headingId' => 'section-'.$index,
                        ])

                        @include('public.sections.'.$section->kind->value, ['section' => $section])
                    </div>
                </section>

                @if (! $loop->last)
                    @include('public.partials.tibeb')
                @endif
            @endif
        @endforeach
    </main>

    <footer class="site-footer">
        <div class="shell site-footer__inner">
            <div class="site-footer__links">
                @if ($page->websiteUrl)
                    <a href="{{ $page->websiteUrl }}" rel="noopener noreferrer nofollow">
                        {{ __('public.website') }}
                    </a>
                @endif

                @foreach ($page->socialLinks as $platform => $url)
                    <a href="{{ $url }}" rel="noopener noreferrer nofollow">
                        {{ __('public.social.'.$platform) }}
                    </a>
                @endforeach
            </div>

            <p class="site-footer__meta">
                <span>&copy; {{ now()->year }} {{ $page->name }}</span>
                <span class="site-footer__powered">
                    {{ __('public.powered_by') }}
                    <a href="https://ethr.et" rel="noopener noreferrer">ETHR</a>
                </span>
            </p>
        </div>
    </footer>
@endsection
