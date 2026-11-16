<?php

namespace VentureDrake\LaravelCrm\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class HostTeamController extends Controller
{
    public function create()
    {
        return redirect()->route('laravel-crm.settings.edit');
    }

    public function store(Request $request)
    {
        abort(403, 'Each account belongs to one business. Use internal teams to group colleagues.');
    }
}
