<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>License · {{ config('app.name') }}</title>
    <style>
        :root { --bg:#f6f7f9; --card:#fff; --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --accent:#4f46e5; --ok:#047857; --okbg:#ecfdf5; --err:#b91c1c; --errbg:#fef2f2; --warnbg:#fffbeb; --warn:#92400e; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0b0d12; --card:#151821; --ink:#f3f4f6; --muted:#9ca3af; --line:#262a36; --accent:#818cf8; --ok:#6ee7b7; --okbg:#052e22; --err:#fca5a5; --errbg:#3b0d0d; --warnbg:#3a2a05; --warn:#fcd34d; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; padding:16px; background:var(--bg); color:var(--ink); font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
        .card { width:100%; max-width:460px; background:var(--card); border:1px solid var(--line); border-radius:14px; padding:28px; }
        h1 { font-size:20px; margin:0 0 4px; }
        p { margin:0 0 16px; color:var(--muted); }
        label { display:block; font-weight:600; margin-bottom:6px; }
        input { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; background:transparent; color:inherit; font:14px ui-monospace,Menlo,monospace; }
        input:focus { outline:2px solid var(--accent); outline-offset:1px; }
        button { margin-top:14px; width:100%; padding:11px; border:0; border-radius:8px; background:var(--accent); color:#fff; font-weight:600; cursor:pointer; }
        button.link { background:none; color:var(--muted); width:auto; padding:0; margin:0; font-weight:500; text-decoration:underline; }
        .note { padding:10px 12px; border-radius:8px; margin-bottom:16px; font-size:14px; }
        .ok { background:var(--okbg); color:var(--ok); } .err { background:var(--errbg); color:var(--err); } .warn { background:var(--warnbg); color:var(--warn); }
        dl { display:grid; grid-template-columns:auto 1fr; gap:6px 14px; margin:0 0 18px; font-size:14px; }
        dt { color:var(--muted); } dd { margin:0; }
        a { color:var(--accent); }
        .foot { margin-top:18px; font-size:13px; color:var(--muted); }
    </style>
</head>
<body>
<main class="card">
    <h1>{{ config('app.name') }} license</h1>

    @if (session('licentra_status'))
        <div class="note ok">{{ session('licentra_status') }}</div>
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
                <dd>Version {{ $state->update['version'] }} is available @if($state->update['url'])· <a href="{{ $state->update['url'] }}" target="_blank" rel="noopener">Get it</a>@endif</dd>
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
</body>
</html>
