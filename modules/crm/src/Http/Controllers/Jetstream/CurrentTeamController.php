<?php

namespace VentureDrake\LaravelCrm\Http\Controllers\Jetstream;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CurrentTeamController extends Controller
{
    public function update(Request $request)
    {
        abort_unless($request->user()?->team_id
            && (string) $request->input('team_id') === (string) $request->user()->team_id, 403);

        return redirect()->route('laravel-crm.dashboard');
    }
}
