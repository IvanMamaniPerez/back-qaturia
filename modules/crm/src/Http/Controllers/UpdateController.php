<?php

namespace VentureDrake\LaravelCrm\Http\Controllers;

use Illuminate\Http\Request;

class UpdateController extends Controller
{
    public function index()
    {
        // Installation maintenance is performed by the platform operator.
        // A tenant's read-only version page must not mutate shared settings
        // or transmit installation/account metadata to a third party.
        return view('laravel-crm::updates.index');
    }

    public function update(Request $request)
    {
        abort(403, 'Platform updates are managed from the deployment console.');
    }
}
