@extends('licentra::layout')

@section('title', 'Update')

@section('content')
@php($inProgress = in_array($status['step'] ?? null, \Pacific\Licentra\Update\Updater::STEPS, true))
<main class="card">
    <h1>Update {{ config('app.name') }}</h1>

    @error('update')<div class="note err" role="alert">{{ $message }}</div>@enderror

    <div id="failed" class="note err" role="alert" @if(($status['step'] ?? null) !== 'failed') hidden @endif>
        <strong>The update didn't finish.</strong>
        <span id="failed-msg">{{ $status['error'] ?? '' }}</span>
    </div>

    <div id="done" class="note ok" @if(($status['step'] ?? null) !== 'done') hidden @endif>
        Updated to version <span id="done-version">{{ $status['version'] ?? '' }}</span>.
    </div>

    <div id="warnings" class="note warn" @if(empty($status['warnings'])) hidden @endif>
        @foreach ($status['warnings'] ?? [] as $warning)<div>{{ $warning }}</div>@endforeach
    </div>

    @if ($update || $inProgress)
        <dl>
            <dt>Installed</dt><dd>{{ $current }}</dd>
            <dt>New</dt><dd><strong>{{ $update['version'] ?? $status['version'] }}</strong></dd>
        </dl>

        @if (!empty($update['changelog']))
            <div class="changelog">{!! \Illuminate\Support\Str::markdown($update['changelog'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
        @endif

        <div id="progress" @if(!$inProgress) hidden @endif>
            <div class="bar"><span id="bar" style="width: {{ $status['progress'] ?? 0 }}%"></span></div>
            <p id="label" style="margin:0 0 16px">{{ $status['label'] ?? 'Starting…' }}</p>
            <p class="foot" style="margin-top:0">Keep this page open. The site is briefly in maintenance mode while files are replaced.</p>
        </div>

        <div id="intro" @if($inProgress) hidden @endif>
            <ul class="small">
                <li>Files that change are backed up first and restored automatically if anything fails.</li>
                <li>Your settings (<code>.env</code>), uploads and <code>storage/</code> are never touched.</li>
                <li>Take a database backup before updating, to be safe.</li>
            </ul>
            <button type="button" id="start" data-version="{{ $update['version'] ?? '' }}">Update to {{ $update['version'] ?? '' }}</button>
        </div>
    @elseif (($status['step'] ?? null) !== 'done')
        <p>You're on the latest version ({{ $current }}).</p>
    @endif

    @if (in_array($status['step'] ?? null, ['failed', 'done'], true))
        <form method="post" action="{{ route('licentra.update.reset') }}" style="margin-top:14px">
            @csrf
            <button type="submit" class="link">Clear this and check again</button>
        </form>
    @endif

    <p class="foot"><a href="{{ route('licentra.activate') }}">← License</a></p>
</main>

@include('licentra::partials.steps')
<script>
(() => {
    const $ = (id) => document.getElementById(id);
    const {post} = window.licentraSteps;

    function show(s) {
        if (s.warnings && s.warnings.length) {
            $('warnings').hidden = false;
            $('warnings').replaceChildren(...s.warnings.map((w) => Object.assign(document.createElement('div'), {textContent: w})));
        }
        if (s.progress !== undefined) $('bar').style.width = s.progress + '%';
        if (s.label) $('label').textContent = s.label + '…';
        if (s.step === 'done') {
            $('progress').hidden = true;
            $('done').hidden = false;
            $('done-version').textContent = s.version;
            if (!s.warnings || !s.warnings.length) setTimeout(() => location.reload(), 1500);
        }
        if (s.step === 'failed') {
            $('progress').hidden = true;
            $('failed').hidden = false;
            $('failed-msg').textContent = s.error || '';
        }
    }

    function run() {
        $('intro') && ($('intro').hidden = true);
        $('progress').hidden = false;
        return window.licentraSteps.run({
            stepUrl: @json(route('licentra.update.step')),
            statusUrl: @json(route('licentra.update.status')),
            onStatus: show,
        });
    }

    const start = $('start');
    start && start.addEventListener('click', async () => {
        start.disabled = true;
        const res = await post(@json(route('licentra.update.start')), {version: start.dataset.version});
        const s = await res.json().catch(() => ({}));
        if (!res.ok) {
            show({step: 'failed', error: s.error || s.message || ('Request failed (HTTP ' + res.status + ').')});
            start.disabled = false;
            return;
        }
        show(s);
        run();
    });

    @if ($inProgress)
        run(); // resume after a reload
    @endif
})();
</script>
@endsection
