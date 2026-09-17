{{-- Never indexable: the layout defaults `$indexable` to false. --}}
@extends('public.layout')

@section('title', __('public.unavailable.title'))

@section('body')
    {{--
        One page for every failure, and deliberately vague about which one.

        An unknown subdomain, a suspended organisation and one that has simply
        not published its page all land here with the same words. Distinguishing
        them would let anyone with a browser enumerate which organisations use
        ETHR, and whether each is in good standing — information that belongs to
        those organisations, not to whoever is guessing hostnames.
    --}}
    <main id="main" class="stateful">
        <div class="shell shell--narrow stateful__inner">
            <p class="stateful__brand">ETHR</p>

            <h1 class="stateful__title">
                @if ($status === 429)
                    {{ __('public.unavailable.rate_limited_title') }}
                @elseif ($status >= 500)
                    {{ __('public.unavailable.error_title') }}
                @else
                    {{ __('public.unavailable.title') }}
                @endif
            </h1>

            <p class="stateful__body">
                @if ($status === 429)
                    {{ __('public.unavailable.rate_limited_body') }}
                @elseif ($status >= 500)
                    {{ __('public.unavailable.error_body') }}
                @else
                    {{ __('public.unavailable.body') }}
                @endif
            </p>

            <p class="stateful__actions">
                <a class="btn btn--primary" href="https://ethr.et">
                    {{ __('public.unavailable.cta') }}
                </a>
            </p>
        </div>
    </main>
@endsection
