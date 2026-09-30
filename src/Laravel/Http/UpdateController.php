<?php

namespace Pacific\Licentra\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Update\UpdateFailed;
use Pacific\Licentra\Update\Updater;

/** One-click update page. The page's script calls step() until the update is done or failed. */
class UpdateController extends Controller
{
    public function show(Updater $updater, Licentra $licentra)
    {
        return view('licentra::update', [
            'update' => $updater->available(),
            'status' => $updater->status(),
            'current' => $licentra->config()->productVersion,
        ]);
    }

    public function start(Request $request, Updater $updater): JsonResponse
    {
        $request->validate(['version' => ['required', 'string', 'max:32']]);

        try {
            return response()->json($updater->start($request->string('version')));
        } catch (UpdateFailed $e) {
            return response()->json(['step' => 'failed', 'error' => $e->getMessage()], 409);
        }
    }

    public function step(Updater $updater): JsonResponse
    {
        try {
            return response()->json($updater->step());
        } catch (UpdateFailed $e) {
            return response()->json(['error' => $e->getMessage(), 'retry' => true] + $updater->status(), 409);
        }
    }

    public function status(Updater $updater): JsonResponse
    {
        return response()->json($updater->status());
    }

    public function reset(Updater $updater)
    {
        try {
            $updater->reset();
        } catch (UpdateFailed $e) {
            return back()->withErrors(['update' => $e->getMessage()]);
        }

        return redirect()->route('licentra.update');
    }
}
