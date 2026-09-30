{{--
    Optional notice for your admin layout:  @include('licentra::banner')
    Shows update-available and support-ending notices. Restyle by publishing views.
--}}
@php($licentraState = app(\Ishalabs\Licentra\Licentra::class)->state())
@if ($licentraState->updateAvailable())
    <div role="status" style="padding:8px 14px;background:#eef2ff;color:#3730a3;font-size:14px">
        {{ config('app.name') }} {{ $licentraState->update['version'] }} is available.
        @if ($licentraState->update['url'])<a href="{{ $licentraState->update['url'] }}" target="_blank" rel="noopener" style="color:inherit;font-weight:600">See what's new</a>@endif
    </div>
@endif
@if ($licentraState->isUsable() && $licentraState->supportEndingSoon() && $licentraState->renewUrl)
    <div role="status" style="padding:8px 14px;background:#fffbeb;color:#92400e;font-size:14px">
        Your support {{ $licentraState->supportActive() ? 'ends on '.$licentraState->supportedUntil->format('M j, Y') : 'has ended' }}.
        <a href="{{ $licentraState->renewUrl }}" target="_blank" rel="noopener" style="color:inherit;font-weight:600">Renew support</a>
    </div>
@endif
