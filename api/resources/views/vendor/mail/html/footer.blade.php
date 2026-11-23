@inject('brand', 'App\Support\MailBrand')
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
<p>{{ $slot }}</p>
@foreach ($brand->lines() as $line)
<p>{{ $line }}</p>
@endforeach
@unless ($brand->isPlatform())
<p>{{ __('mail.sent_with_ethr') }}</p>
@endunless
</td>
</tr>
</table>
</td>
</tr>
