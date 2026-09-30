@extends('licentra::layout')

@section('content')
<main class="card">
    <h1>{{ config('app.name') }} license</h1>

    @if (session('licentra_status'))
        <div class="note ok">{{ session('licentra_status') }}</div>
    @endif
    {{-- The host app's own middleware (e.g. a demo-mode guard) may reject the form with a flash error. --}}
    @if (session('error'))
        <div class="note err" role="alert">{{ session('error') }}</div>
    @endif

    @if ($state->isUsable())
        @if ($state->status->value === 'pending')
            <div class="note warn">{{ $state->message() }}</div>
        @endif
        <dl>
            <dt>Status</dt><dd>Active</dd>
            <dt>Domain</dt><dd>{{ $state->domain }}</dd>
            @if ($state->email)<dt>Contact</dt><dd>{{ $state->email }}</dd>@endif
            @if ($state->licenseType)<dt>License</dt><dd>{{ $state->licenseType }}</dd>@endif
            @if ($state->supportedUntil)
                <dt>Support</dt>
                <dd>
                    {{ $state->supportActive() ? 'Until' : 'Ended' }} {{ $state->supportedUntil->format('M j, Y') }}
                    @if ($state->supportEndingSoon() && $state->renewUrl) · <a href="{{ $state->renewUrl }}" target="_blank" rel="noopener">Renew</a>@endif
                </dd>
            @endif
            @if ($state->updateAvailable())
                <dt>Update</dt>
                <dd>Version {{ $state->update['version'] }} is available ·
                    @if (app(\Pacific\Licentra\Update\Updater::class)->available())
                        <a href="{{ route('licentra.update') }}">Update now</a>
                    @elseif ($state->update['url'])
                        <a href="{{ $state->update['url'] }}" target="_blank" rel="noopener">Get it</a>
                    @endif
                </dd>
            @endif
        </dl>

        <details>
            <summary style="cursor:pointer;color:var(--muted);font-size:14px">Move this license to another domain</summary>
            <form method="post" action="{{ route('licentra.deactivate') }}" style="margin-top:12px">
                @csrf
                <label for="dc">Purchase code</label>
                <input id="dc" name="purchase_code" required autocomplete="off" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                @error('deactivate')<div class="note err" style="margin:10px 0 0">{{ $message }}</div>@enderror
                <button type="submit" style="background:var(--err)">Release this domain</button>
            </form>
        </details>
    @else
        <p>{{ $state->message() }}</p>
        <form method="post" action="{{ route('licentra.store') }}">
            @csrf
            <label for="pc">Envato purchase code</label>
            <input id="pc" name="purchase_code" value="{{ old('purchase_code') }}" required autofocus autocomplete="off"
                   placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" aria-describedby="pc-help">
            @error('purchase_code')<div class="note err" style="margin:10px 0 0" role="alert">{{ $message }}</div>@enderror
            <label for="em" style="margin-top:14px">Email</label>
            <input id="em" name="email" type="email" value="{{ old('email', auth()->user()?->email) }}" required autocomplete="email"
                   placeholder="you@example.com" aria-describedby="em-help" style="font-family:inherit">
            @error('email')<div class="note err" style="margin:10px 0 0" role="alert">{{ $message }}</div>@enderror
            <p class="foot" id="em-help" style="margin:6px 0 0">Used only for license and update notices about this product.</p>
            <button type="submit">Activate</button>
        </form>
        <p class="foot" id="pc-help">
            Find it in your CodeCanyon <a href="https://codecanyon.net/downloads" target="_blank" rel="noopener">Downloads</a> page → Download → "License certificate &amp; purchase code".
            Local and staging domains don't use up your license.
        </p>
    @endif
</main>
@endsection
