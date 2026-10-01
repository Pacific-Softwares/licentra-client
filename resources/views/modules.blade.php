@extends('licentra::layout')

@section('title', 'Modules')

@section('content')
@php($inProgress = in_array($status['step'] ?? null, \Pacific\Licentra\Modules\ModuleInstaller::STEPS, true))
<style>
    .card.wide { max-width: 720px; }
    .mod { border:1px solid var(--line); border-radius:10px; padding:14px 16px; margin-bottom:10px; }
    .mod-head { display:flex; gap:10px; align-items:baseline; flex-wrap:wrap; }
    .mod-head strong { font-size:15px; }
    .mod-head .ver { color:var(--muted); font-size:13px; }
    .pill { font-size:12px; font-weight:600; padding:2px 8px; border-radius:99px; margin-left:auto; }
    .pill.on { background:var(--okbg); color:var(--ok); } .pill.off { background:var(--line); color:var(--muted); }
    .pill.attn { background:var(--errbg); color:var(--err); } .pill.dev { background:var(--warnbg); color:var(--warn); }
    .mod p { margin:6px 0 0; font-size:14px; }
    .mod details { margin-top:10px; font-size:14px; }
    .mod summary { cursor:pointer; color:var(--accent); }
    .row { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
    .row button { width:auto; margin:0; padding:8px 14px; }
    button.ghost { background:transparent; color:var(--ink); border:1px solid var(--line); }
    button.danger { background:var(--err); }
    h2 { font-size:15px; margin:22px 0 10px; }
    .caps { font-size:13px; color:var(--muted); margin:6px 0 0; padding-left:18px; }
    form.inline label { font-weight:500; font-size:13px; margin:10px 0 4px; }
</style>
<main class="card wide">
    <h1>Modules</h1>
    <p>Add-ons for {{ config('app.name') }}. Installing a module adds its code to your whole site, so every change asks for your password.</p>

    @if ($config['safe_mode'])
        <div class="note warn">Safe mode is on (<code>LICENTRA_MODULES_SAFE=true</code>): no modules are loaded.</div>
    @endif
    @if ($config['dev'])
        <div class="note warn">Developer mode is on: unsigned modules in <code>modules-dev/</code> are loaded. They are not checked, licensed or supported.</div>
    @endif
    @if (session('licentra_modules'))
        <div class="note ok" role="status">{{ session('licentra_modules') }}</div>
    @endif
    @error('modules')<div class="note err" role="alert">{{ $message }}</div>@enderror

    <div id="failed" class="note err" role="alert" @if(($status['step'] ?? null) !== 'failed') hidden @endif>
        <strong>The install didn't finish.</strong> <span id="failed-msg">{{ $status['error'] ?? '' }}</span>
    </div>
    <div id="done" class="note ok" @if(($status['step'] ?? null) !== 'done') hidden @endif>
        Installed <span id="done-name">{{ $status['slug'] ?? '' }}</span> <span id="done-version">{{ $status['version'] ?? '' }}</span>.
    </div>
    <div id="progress" @if(!$inProgress) hidden @endif>
        <div class="bar"><span id="bar" style="width: {{ $status['progress'] ?? 0 }}%"></span></div>
        <p id="label" style="margin:0 0 16px">{{ $status['label'] ?? 'Starting…' }}</p>
    </div>
    @if (in_array($status['step'] ?? null, ['failed', 'done'], true))
        <form method="post" action="{{ route('licentra.modules.reset') }}" style="margin-bottom:12px">
            @csrf
            <button type="submit" class="link">Dismiss</button>
        </form>
    @endif

    <h2>Installed</h2>
    @forelse ($installed as $slug => $m)
        @php($pill = match (true) {
            !empty($m['dev']) => ['dev', 'Unsigned'],
            ($m['status'] ?? null) === 'needs_attention' => ['attn', 'Needs attention'],
            ($m['status'] ?? null) === 'enabled' && $m['running'] => ['on', 'Active'],
            ($m['status'] ?? null) === 'enabled' => ['attn', 'Not running'],
            default => ['off', 'Off'],
        })
        <div class="mod">
            <div class="mod-head">
                <strong>{{ $m['name'] ?? $slug }}</strong>
                <span class="ver">{{ $slug }} · {{ $m['version'] ?? '' }}</span>
                <span class="pill {{ $pill[0] }}">{{ $pill[1] }}</span>
            </div>
            @if (!empty($m['warning']))<p class="note warn" style="margin-top:8px">{{ $m['warning'] }}</p>@endif
            @if (!empty($m['note']) && $pill[0] !== 'on')<p>{{ $m['note'] }}</p>@endif
            @if (!empty($m['update']['version']) && version_compare($m['update']['version'], $m['version'], '>'))
                <p>Version {{ $m['update']['version'] }} is available.</p>
            @endif

            @if (empty($m['dev']))
                <details>
                    <summary>Manage</summary>
                    <form method="post" action="{{ route('licentra.modules.manage', $slug) }}" class="inline">
                        @csrf
                        <label for="pw-{{ $slug }}">Your password</label>
                        <input id="pw-{{ $slug }}" name="password" type="password" autocomplete="current-password" required>
                        <div class="row">
                            @if (($m['status'] ?? null) === 'needs_attention')
                                <button name="action" value="retry">Retry database update</button>
                            @elseif (($m['status'] ?? null) === 'enabled')
                                <button name="action" value="disable" class="ghost">Switch off</button>
                            @else
                                <button name="action" value="enable" @disabled(!empty($m['license_problem']))>Switch on</button>
                            @endif
                            @if (!empty($m['update']['version']) && version_compare($m['update']['version'], $m['version'], '>'))
                                <button type="button" class="ghost js-update" data-slug="{{ $slug }}">Update to {{ $m['update']['version'] }}</button>
                            @endif
                            <button name="action" value="uninstall" class="ghost" onclick="return confirm('Remove {{ $m['name'] ?? $slug }}? Its data is kept unless you also tick “delete data”.')">Uninstall</button>
                        </div>
                        <label style="display:flex;gap:8px;align-items:center;font-weight:500;margin-top:12px">
                            <input type="checkbox" name="delete_data" value="1" style="width:auto"> Also delete this module's data (cannot be undone)
                        </label>
                        <label for="confirm-{{ $slug }}">To delete data, type <code>{{ $slug }}</code></label>
                        <input id="confirm-{{ $slug }}" name="confirm_slug" autocomplete="off">
                    </form>
                </details>
            @endif
        </div>
    @empty
        <p>No modules installed yet.</p>
    @endforelse

    <h2>Available</h2>
    @if (!$licensed)
        <p>Activate your license to see and install add-ons. <a href="{{ route('licentra.activate') }}">License</a></p>
    @elseif ($storeError)
        <div class="note warn">{{ $storeError }}</div>
    @else
        @forelse ($available as $a)
            <div class="mod">
                <div class="mod-head">
                    <strong>{{ $a['name'] }}</strong>
                    <span class="ver">{{ $a['slug'] }}{{ $a['version'] ? ' · ' . $a['version'] : '' }}</span>
                    <span class="pill {{ $a['pricing'] === 'free' ? 'on' : 'off' }}">{{ $a['pricing'] === 'free' ? 'Free' : 'Paid' }}</span>
                </div>
                @if (!$a['version'])
                    <p>Not released yet.</p>
                @else
                    <form class="inline js-install" data-slug="{{ $a['slug'] }}">
                        @if ($a['pricing'] !== 'free')
                            <p>@if ($a['url'])<a href="{{ $a['url'] }}" target="_blank" rel="noopener">Buy on CodeCanyon</a>, then paste @else Paste @endif its purchase code.</p>
                            <label for="code-{{ $a['slug'] }}">Purchase code</label>
                            <input id="code-{{ $a['slug'] }}" name="purchase_code" required placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" autocomplete="off">
                        @endif
                        <label for="ipw-{{ $a['slug'] }}">Your password</label>
                        <input id="ipw-{{ $a['slug'] }}" name="password" type="password" autocomplete="current-password" required>
                        <div class="row"><button type="submit">Install</button></div>
                    </form>
                @endif
            </div>
        @empty
            <p>No other add-ons are available for {{ config('app.name') }} yet.</p>
        @endforelse
    @endif

    <p class="foot">
        @if ($config['back_url'])<a href="{{ $config['back_url'] }}">← Back</a> · @endif
        <a href="{{ route('licentra.activate') }}">License</a>
        @if (\Illuminate\Support\Facades\Route::has('licentra.update')) · <a href="{{ route('licentra.update') }}">Update</a>@endif
    </p>
</main>

@include('licentra::partials.steps')
<script>
(() => {
    const $ = (id) => document.getElementById(id);
    const {post} = window.licentraSteps;

    function show(s) {
        if (s.progress !== undefined) $('bar').style.width = s.progress + '%';
        if (s.label) $('label').textContent = s.label + '…';
        if (s.step === 'done') {
            $('progress').hidden = true;
            $('done').hidden = false;
            $('done-name').textContent = s.slug || '';
            $('done-version').textContent = s.version || '';
            setTimeout(() => location.reload(), 1500);
        }
        if (s.step === 'failed') {
            $('progress').hidden = true;
            $('failed').hidden = false;
            $('failed-msg').textContent = s.error || '';
        }
    }

    function run() {
        $('failed').hidden = true;
        $('progress').hidden = false;
        window.scrollTo({top: 0, behavior: 'smooth'});
        return window.licentraSteps.run({
            stepUrl: @json(route('licentra.modules.step')),
            statusUrl: @json(route('licentra.modules.status')),
            onStatus: show,
        });
    }

    async function start(body, button) {
        button.disabled = true;
        const res = await post(@json(route('licentra.modules.start')), body);
        const s = await res.json().catch(() => ({}));
        if (!res.ok) {
            const error = s.error || (s.errors && Object.values(s.errors)[0][0]) || s.message || ('Request failed (HTTP ' + res.status + ').');
            show({step: 'failed', error});
            button.disabled = false;
            return;
        }
        show(s);
        run();
    }

    document.querySelectorAll('form.js-install').forEach((form) => form.addEventListener('submit', (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(form));
        start({slug: form.dataset.slug, purchase_code: data.purchase_code || null, password: data.password}, form.querySelector('button'));
    }));

    document.querySelectorAll('button.js-update').forEach((button) => button.addEventListener('click', () => {
        const password = button.closest('form').querySelector('input[name=password]').value;
        if (!password) { button.closest('form').querySelector('input[name=password]').focus(); return; }
        start({slug: button.dataset.slug, password}, button);
    }));

    @if ($inProgress)
        run(); // resume after a reload
    @endif
})();
</script>
@endsection
