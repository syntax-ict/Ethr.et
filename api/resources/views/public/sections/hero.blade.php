{{--
    The page's opening block, and the only <h1> on it.

    Reads the profile rather than storing its own copy of the headline. One
    source of truth per field: `tenant_public_profiles` owns identity, sections
    own the page body, and a hero that kept its own headline would mean two
    places to edit it and one of them going stale.
--}}
<section class="hero @if ($page->hasHero) hero--with-image @endif">
    @if ($page->hasHero)
        <img class="hero__image" src="/media/hero" alt="" aria-hidden="true">
    @endif

    <div class="hero__content shell">
        <h1 class="hero__title">{{ $page->name }}</h1>

        @if ($page->headline)
            <p class="hero__headline">{{ $page->headline }}</p>
        @endif

        <p class="hero__actions">
            <a class="btn btn--primary btn--lg" href="/login">{{ __('public.employee_sign_in') }}</a>
        </p>
    </div>
</section>
