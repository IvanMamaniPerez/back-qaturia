<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IngestLiveBatchRequest;
use App\Services\LiveAccessService;
use App\Services\LiveIngestionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class LiveBatchController extends Controller
{
    /**
     * Persist a bounded batch atomically; 200 includes per-event rejections. accepted means committed ingestion, not commercial processing.
     *
     * @return array{batchId: string, results: list<array{eventId: string|null, status: string, code: string|null, canonicalEventId: string|null, errors: object}>}|JsonResponse
     */
    public function store(IngestLiveBatchRequest $request, LiveAccessService $access, LiveIngestionService $ingestion): array|JsonResponse
    {
        $data = $request->validated();
        $session = $access->session($request->user(), $data['liveSessionId']);
        try {
            $results = $ingestion->ingest($request->user(), $session, $data['events']);
        } catch (QueryException $exception) {
            report($exception);

            return response()->json(['code' => 'PERSISTENCE_UNAVAILABLE', 'message' => 'Batch was not acknowledged. Retry the same event identities.'], 503);
        }
        $results = array_map(fn (array $result): array => [...$result, 'errors' => (object) $result['errors']], $results);

        return ['batchId' => $data['batchId'], 'results' => $results];
    }
}
