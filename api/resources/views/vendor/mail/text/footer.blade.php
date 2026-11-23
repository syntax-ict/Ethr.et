@inject('brand', 'App\Support\MailBrand')
{{ $slot }}
@foreach ($brand->lines() as $line)
{{ $line }}
@endforeach
@unless ($brand->isPlatform())
{{ __('mail.sent_with_ethr') }}
@endunless
