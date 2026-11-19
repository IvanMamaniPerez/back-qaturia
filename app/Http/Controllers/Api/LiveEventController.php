<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListLiveEventsRequest;
use App\Http\Resources\LiveEventResource;
use App\Services\LiveAccessService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LiveEventController extends Controller
{
    public function index(ListLiveEventsRequest $request, string $session, LiveAccessService $access): AnonymousResourceCollection
    {
        $session = $access->session($request->user(), $session);

        return LiveEventResource::collection($session->events()
            ->leftJoin('live_comment_states', 'live_events.id', '=', 'live_comment_states.live_event_id')
            ->select('live_events.*', 'live_comment_states.deleted_at as comment_deleted_at')
            ->when($request->filled('eventType'), fn ($query) => $query->where('event_type', $request->string('eventType')->toString()))
            ->orderBy('observed_at')->orderBy('live_events.id')->paginate($request->integer('per_page', 25))->withQueryString());
    }
}
