<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'License') · {{ config('app.name') }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
        .bar { height:8px; border-radius:99px; background:var(--line); overflow:hidden; margin:6px 0 10px; }
        .bar > span { display:block; height:100%; width:0; background:var(--accent); transition:width .4s; }
        .changelog { font-size:14px; background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:10px 12px; max-height:220px; overflow:auto; margin:0 0 16px; }
        .changelog h1, .changelog h2, .changelog h3 { font-size:14px; margin:8px 0 4px; }
        .changelog ul { margin:0; padding-left:18px; } .changelog p { margin:4px 0; color:inherit; }
        ul.small { margin:0 0 16px; padding-left:18px; font-size:14px; color:var(--muted); }
    </style>
</head>
<body>
@yield('content')
</body>
</html>
