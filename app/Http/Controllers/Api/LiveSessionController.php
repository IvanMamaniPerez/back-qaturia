<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListLiveEventsRequest;
use App\Http\Requests\StoreLiveSessionRequest;
use App\Http\Resources\LiveSessionResource;
use App\Models\Business;
use App\Models\LiveSession;
use App\Services\LiveAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class LiveSessionController extends Controller
{
    public function __construct(private LiveAccessService $access) {}

    public function index(ListLiveEventsRequest $request): AnonymousResourceCollection
    {
        return LiveSessionResource::collection(LiveSession::where('business_id', $request->user()->team_id)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($request->integer('per_page', 25))->withQueryString());
    }

    /** Create a room authorization. Retrying clientSessionId returns the existing session; a different room returns 409. */
    public function store(StoreLiveSessionRequest $request): LiveSessionResource
    {
        $data = $request->validated();
        $session = DB::transaction(function () use ($request, $data): LiveSession {
            Business::whereKey($request->user()->team_id)->lockForUpdate()->firstOrFail();
            $session = LiveSession::firstOrCreate(['business_id' => $request->user()->team_id, 'client_session_id' => $data['clientSessionId']], [
                'created_by' => $request->user()->id, 'platform' => $data['platform'], 'source_room_id' => $data['sourceRoomId'],
                'creator' => $data['creator'] ?? null, 'initial_creator' => $data['creator'] ?? null,
                'title' => $data['title'] ?? null, 'initial_title' => $data['title'] ?? null, 'status' => 'initialized',
            ]);
            abort_unless($session->source_room_id === $data['sourceRoomId'] && $session->platform === $data['platform'], 409, 'SESSION_IDENTITY_CONFLICT');

            return $session;
        }, 3);

        return new LiveSessionResource($session);
    }

    public function show(Request $request, string $session): LiveSessionResource
    {
        return new LiveSessionResource($this->access->session($request->user(), $session));
    }
}
