<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListLiveEventsRequest;
use App\Services\LiveAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

class LiveCoverageController extends Controller
{
    /**
     * Observed intervals by instance and epoch. Gaps between intervals and time after lastObservedAt are unverified; these are not billable minutes.
     *
     * @return array{data: list<array{id: string, connectorId: string, connectionEpoch: string, startedAt: string, lastObservedAt: string, endedAt: string|null, endReason: string|null}>, meta: array{page: int, perPage: int, total: int}}
     */
    public function index(ListLiveEventsRequest $request, string $session, LiveAccessService $access): array
    {
        $session = $access->session($request->user(), $session);
        $page = DB::table('live_coverage_intervals')->where('live_session_id', $session->id)->orderBy('started_at')->orderBy('id')->paginate($request->integer('per_page', 25))->withQueryString();
        $data = array_values($page->getCollection()->map(fn (stdClass $interval): array => [
            'id' => (string) $interval->id, 'connectorId' => (string) $interval->live_connector_id,
            'connectionEpoch' => (string) $interval->connection_epoch,
            'startedAt' => CarbonImmutable::parse($interval->started_at, 'UTC')->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'lastObservedAt' => CarbonImmutable::parse($interval->last_observed_at, 'UTC')->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'endedAt' => $interval->ended_at ? CarbonImmutable::parse($interval->ended_at, 'UTC')->utc()->format('Y-m-d\TH:i:s.u\Z') : null,
            'endReason' => $interval->end_reason ? (string) $interval->end_reason : null,
        ])->all());

        return ['data' => $data, 'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total()]];
    }
}
