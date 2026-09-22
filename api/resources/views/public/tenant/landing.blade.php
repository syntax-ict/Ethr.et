{{--
    The tenant's public landing page.

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
        <section class="hero {{ $page->hasHero ? 'hero--with-image' : '' }}">
            @if ($page->hasHero)
                <img class="hero__image" src="{{ url('/media/hero') }}" alt="" aria-hidden="true">
            @endif

            <div class="shell hero__content">
                <h1 class="hero__title">{{ $page->name }}</h1>

                @if ($page->headline)
                    <p class="hero__headline">{{ $page->headline }}</p>
                @endif

                <p class="hero__actions">
                    <a class="btn btn--primary btn--lg" href="/login">
                        {{ __('public.employee_sign_in') }}
                    </a>
                </p>
            </div>
        </section>

        @include('public.partials.tibeb')

        @if ($page->description)
            <section class="section" aria-labelledby="about-heading">
                <div class="shell shell--narrow">
                    <h2 id="about-heading" class="section__title">
                        {{ __('public.about_heading', ['organization' => $page->name]) }}
                    </h2>
                    {{-- Split into paragraphs and echoed normally. The obvious
                         alternative is an unescaped echo of nl2br() over an
                         escaped string, which is safe in itself but puts a raw
                         echo on a page that renders tenant-entered text — and
                         then "no raw echoes here" needs an exception, which is
                         one review away from a second one. A loop costs nothing
                         and keeps the rule absolute and greppable. --}}
                    <div class="prose">
                        @foreach (preg_split('/\R{2,}/', trim($page->description)) as $paragraph)
                            <p>{{ $paragraph }}</p>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        @if ($page->hasContactDetails())
            @include('public.partials.tibeb')

            <section class="section" aria-labelledby="contact-heading">
                <div class="shell shell--narrow">
                    <h2 id="contact-heading" class="section__title">{{ __('public.contact_heading') }}</h2>

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
                </div>
            </section>
        @endif
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
