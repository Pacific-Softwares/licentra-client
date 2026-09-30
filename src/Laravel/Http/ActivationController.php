<?php

namespace Pacific\Licentra\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pacific\Licentra\Exceptions\ActivationFailed;
use Pacific\Licentra\Exceptions\LicentraException;
use Pacific\Licentra\Exceptions\ServerUnreachable;
use Pacific\Licentra\Licentra;

class ActivationController extends Controller
{
    public function show(Licentra $licentra)
    {
        return view('licentra::activate', ['state' => $licentra->state()]);
    }

    public function store(Request $request, Licentra $licentra)
    {
        $request->validate([
            'purchase_code' => ['required', 'string', 'max:64'],
            'email' => ['required', 'string', 'email', 'max:254'],
        ]);
        $licentra->observeHost($request->getHost());

        try {
            $state = $licentra->activate($request->string('purchase_code'), $request->string('email'));
        } catch (ActivationFailed $e) {
            return back()->withInput()->withErrors(['purchase_code' => $e->getMessage()]);
        } catch (ServerUnreachable) {
            return back()->withInput()->withErrors(['purchase_code' => 'Could not reach the license server. Check your internet connection and try again.']);
        } catch (LicentraException $e) {
            report($e);

            return back()->withInput()->withErrors(['purchase_code' => $e->getMessage()]);
        }

        return back()->with('licentra_status', $state->message());
    }

    public function destroy(Request $request, Licentra $licentra)
    {
        $request->validate(['purchase_code' => ['required', 'string', 'max:64']]);

        try {
            $licentra->deactivate($request->string('purchase_code'));
        } catch (LicentraException $e) {
            return back()->withErrors(['deactivate' => $e->getMessage()]);
        }

        return back()->with('licentra_status', 'License released. You can now activate it on another domain.');
    }
}
