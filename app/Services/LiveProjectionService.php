<?php

namespace App\Services;

use App\Models\LiveConnector;
use App\Models\LiveEvent;
use App\Models\LiveEventReceipt;
use App\Models\LiveSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LiveProjectionService
{
    public function apply(LiveSession $session, LiveEvent $event): void
    {
        $projection = $session->projection ?? [];
        $payload = $event->payload;
        $time = $event->observed_at->utc()->format('Y-m-d\TH:i:s.u\Z');
        $order = $time.':'.$event->event_id;
        $type = $event->event_type;
        if ($type === 'live.comment.received.v1') {
            $deletedAt = $session->events()->where('event_type', 'live.moderation.observed.v1')->where('source_event_type', 'IM_DELETE')
                ->where('target_message_id', $event->source_message_id)->when($event->source_message_id === null, fn ($query) => $query->whereRaw('1 = 0'))->min('observed_at');
            DB::table('live_comment_states')->insert(['live_event_id' => $event->id, 'deleted_at' => $deletedAt]);
        }
        if ($type === 'live.moderation.observed.v1' && $event->source_event_type === 'IM_DELETE' && isset($payload['targetMessageId'])) {
            $ids = $session->events()->where('event_type', 'live.comment.received.v1')->where('source_message_id', $payload['targetMessageId'])->select('id');
            DB::table('live_comment_states')->whereIn('live_event_id', $ids)->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('deleted_at', '>', $event->observed_at))->update(['deleted_at' => $event->observed_at->format('Y-m-d H:i:s.u')]);
        }
        if ($type === 'live.social.action.v1') {
            $action = $payload['action'];
            $projection[$action.'Count'] = ($projection[$action.'Count'] ?? 0) + 1;
        }
        foreach (match ($type) {
            'live.audience.sampled.v1' => ['audience' => $payload + ['observedAt' => $time]],
            'live.likes.sampled.v1' => ['likes' => $payload + ['observedAt' => $time]],
            default => [],
        } as $key => $value) {
            if ($order > ($projection['watermarks'][$key] ?? '')) {
                $projection[$key] = $value;
                $projection['watermarks'][$key] = $order;
            }
        }
        if ($type === 'live.likes.sampled.v1' && isset($payload['total']) && $order > ($projection['watermarks']['likesTotal'] ?? '')) {
            $projection['likesTotal'] = $payload['total'];
            $projection['watermarks']['likesTotal'] = $order;
        }
        if (in_array($type, ['live.room.metadata.v1', 'live.started.v1'], true) && isset($payload['title']) && $order > ($projection['watermarks']['title'] ?? '')) {
            $session->title = $payload['title'];
            $projection['watermarks']['title'] = $order;
        }
        if (in_array($type, ['live.room.metadata.v1', 'live.started.v1'], true) && isset($payload['creator']) && $order > ($projection['watermarks']['creator'] ?? '')) {
            $session->creator = $payload['creator'];
            $projection['watermarks']['creator'] = $order;
        }
        if ($type === 'live.started.v1' && $order > ($projection['startOrder'] ?? '')) {
            $projection['startOrder'] = $order;
        }
        if ($type === 'live.connector.status.v1') {
            $connector = LiveConnector::findOrFail($event->live_connector_id);
            if ($order > ($connector->state_order ?? '')) {
                $connector->update(['observed_state' => $payload['state'], 'state_observed_at' => $event->observed_at, 'state_order' => $order]);
            }
        }
        $administrative = match ($type) {
            'live.stream.control.v1' => match ($payload['action']) {
                'suspend' => 'suspended', 'stop' => 'stopped', 'end_unverified' => 'ended_unverified',
                'end_confirmed' => 'ended_confirmed', 'resume' => 'tracking', default => null,
            },
            'live.ended.v1' => match ($payload['kind']) {
                'confirmed' => 'ended_confirmed', 'unverified' => 'ended_unverified', 'manual' => 'stopped', 'suspended' => 'suspended', default => null,
            },
            default => null,
        };
        if ($administrative === 'ended_confirmed') {
            $projection['confirmedEnd'] = true;
        } elseif ($administrative && $order > ($projection['manualOrder'] ?? '')) {
            $projection['manualState'] = $administrative === 'tracking' ? null : $administrative;
            $projection['manualOrder'] = $order;
            if ($administrative === 'tracking') {
                $projection['startOrder'] = max($projection['startOrder'] ?? '', $order);
            }
        }
        $connected = $session->connectors()->whereNull('revoked_at')->whereIn('observed_state', ['connected', 'tracking'])->exists();
        $latest = $session->connectors()->whereNotNull('state_order')->orderByDesc('state_order')->first();
        $observed = $connected ? 'tracking' : (($projection['startOrder'] ?? '') > ($latest->state_order ?? '') ? 'tracking' : ($latest->observed_state ?? 'initialized'));
        if (in_array($observed, ['disconnected', 'error', 'reconnecting'], true)) {
            $observed = 'reconnecting';
        }
        $session->status = ($projection['confirmedEnd'] ?? false) ? 'ended_confirmed' : ($projection['manualState'] ?? $observed);
        $session->first_observed_at = $session->first_observed_at?->min($event->observed_at) ?? $event->observed_at;
        $session->last_observed_at = $session->last_observed_at?->max($event->observed_at) ?? $event->observed_at;
        $session->projection = $projection;
        $session->save();
    }

    /** @param array<string, mixed> $event */
    public function observeCoverage(LiveSession $session, string $connectorId, array $event): void
    {
        $time = CarbonImmutable::parse($event['observedAt'])->utc();
        $interval = DB::table('live_coverage_intervals')->where('live_connector_id', $connectorId)->where('connection_epoch', $event['connectionEpoch'])->first();
        $end = match ($event['eventType']) {
            'live.connector.status.v1' => in_array($event['payload']['state'], ['disconnected', 'error', 'suspended', 'stopped', 'ended_unverified'], true) ? $event['payload']['state'] : null,
            'live.stream.control.v1' => in_array($event['payload']['action'], ['suspend', 'stop', 'end_unverified', 'end_confirmed'], true) ? $event['payload']['action'] : null,
            'live.ended.v1' => $event['payload']['kind'],
            default => null,
        };
        $attributes = [
            'started_at' => ($interval ? CarbonImmutable::parse($interval->started_at, 'UTC')->min($time) : $time)->format('Y-m-d H:i:s.u'),
            'last_observed_at' => ($interval ? CarbonImmutable::parse($interval->last_observed_at, 'UTC')->max($time) : $time)->format('Y-m-d H:i:s.u'),
        ];
        if ($end && (! $interval?->ended_at || $time->lt(CarbonImmutable::parse($interval->ended_at)))) {
            $attributes += ['ended_at' => $time->format('Y-m-d H:i:s.u'), 'end_reason' => $end];
        }
        if ($interval) {
            DB::table('live_coverage_intervals')->where('id', $interval->id)->update($attributes);
        } else {
            DB::table('live_coverage_intervals')->insert($attributes + [
                'id' => (string) Str::uuid(), 'live_session_id' => $session->id,
                'live_connector_id' => $connectorId, 'connection_epoch' => $event['connectionEpoch'],
            ]);
        }
    }

    public function rebuild(LiveSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $session = LiveSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            DB::table('live_comment_states')->whereIn('live_event_id', $session->events()->select('id'))->delete();
            DB::table('live_coverage_intervals')->where('live_session_id', $session->id)->delete();
            $session->connectors()->update(['observed_state' => 'initialized', 'state_observed_at' => null, 'state_order' => null]);
            $session->forceFill(['status' => 'initialized', 'title' => $session->initial_title, 'creator' => $session->initial_creator, 'projection' => [], 'first_observed_at' => null, 'last_observed_at' => null])->save();
            foreach ($session->events()->orderBy('observed_at')->orderBy('event_id')->cursor() as $event) {
                $this->apply($session, $event);
            }
            foreach (LiveEventReceipt::whereIn('live_connector_id', $session->connectors()->select('id'))->cursor() as $receipt) {
                $this->observeCoverage($session, $receipt->live_connector_id, $receipt->envelope);
            }
        }, 3);
    }
}
