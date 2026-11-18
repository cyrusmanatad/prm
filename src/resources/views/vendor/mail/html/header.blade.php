@props(['url'])
<tr>
<td class="header" align="center">
<table class="brand" align="center" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="brand-mark">
<a href="{{ $url }}"><img src="{{ rtrim(config('app.url'), '/') }}/portal-assets/email-logo.png" class="logo" width="40" height="40" alt=""></a>
</td>
<td class="brand-name">
<a href="{{ $url }}">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
