{{-- Runs a stepped operation (product update, module install): POST step until done/failed,
     surviving timeouts and maintenance blips. Used by update.blade.php and modules.blade.php. --}}
<script>
window.licentraSteps = window.licentraSteps || (() => {
    const token = document.querySelector('meta[name=csrf-token]').content;
    const post = (url, body) => fetch(url, {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'Content-Type': 'application/json'},
        body: JSON.stringify(body || {}),
        credentials: 'same-origin',
    });
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    async function run({stepUrl, statusUrl, onStatus}) {
        let failures = 0;
        for (;;) {
            let s;
            try {
                const res = await post(stepUrl);
                s = await res.json().catch(() => ({}));
                if (res.status === 409 && s.retry) { await sleep(2000); continue; } // another step still running
                if (!res.ok && res.status !== 409) {
                    // Rejected before reaching the server-side step (demo mode, expired session, ...).
                    onStatus({step: 'failed', error: s.message || s.error || ('Request failed (HTTP ' + res.status + ').')});
                    return;
                }
            } catch (e) {
                // Timed out or the site was briefly unavailable: see where the operation got to.
                if (++failures > 20) { onStatus({step: 'failed', error: 'Lost contact with the site. Reload this page to see the status.'}); return; }
                await sleep(3000);
                try { s = await (await fetch(statusUrl, {credentials: 'same-origin'})).json(); } catch (_) { continue; }
            }
            onStatus(s);
            if (s.step === 'done' || s.step === 'failed') return;
        }
    }

    return {post, run};
})();
</script>
