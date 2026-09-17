{{--
    The shell for every public tenant page.

    Deliberately self-contained: one stylesheet, two fonts, no JavaScript and no
    build step. That is what lets this page render on Ethio Telecom shared
    hosting whether or not a Node runtime turns out to be available, which is
    still an open hosting question at the time of writing. Nothing here may grow
    a dependency on the Next.js application.

    `$page` is a PublicTenantPage — never a Tenant or a TenantPublicProfile. A
    template cannot leak a field it was never handed.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title')</title>

    @hasSection('description')
        <meta name="description" content="@yield('description')">
    @endif

    @unless ($indexable ?? false)
        <meta name="robots" content="noindex, nofollow">
    @endunless

    @isset($canonicalUrl)
        <link rel="canonical" href="{{ $canonicalUrl }}">
    @endisset

    @yield('meta')

    <link rel="stylesheet" href="/assets/ethr-public.css">
</head>
{{--
    Tenant brand colours arrive as a style attribute rather than a <style>
    block. Both are inline as far as CSP is concerned, but an attribute is
    covered by `style-src-attr`, which PublicSecurityHeaders declares
    explicitly — so the allowance is one named directive rather than
    `'unsafe-inline'` on all styles. The values are re-validated as six-digit
    hex in PublicTenantPage::safeTheme() before they get here.
--}}
<body @isset($themeStyle) style="{{ $themeStyle }}" @endisset>
    <a class="skip-link" href="#main">{{ __('public.skip_to_content') }}</a>

    @yield('body')
</body>
</html>
