<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LiveEventSchema
{
    public const SOURCES = [
        'live.started.v1' => ['CONNECTED', 'ENTER_ROOM'],
        'live.comment.received.v1' => ['CHAT', 'QUESTION_NEW'],
        'live.audience.sampled.v1' => ['ROOM_USER'],
        'live.likes.sampled.v1' => ['LIKE'],
        'live.social.action.v1' => ['FOLLOW', 'SHARE', 'SOCIAL'],
        'live.system.notice.v1' => ['NOTICE', 'SYSTEM'],
        'live.moderation.observed.v1' => ['IM_DELETE', 'UNAUTHORIZED_MEMBER', 'MSG_DETECT', 'ROOM_VERIFY', 'ACCESS_CONTROL', 'ACCESS_RECALL', 'PERCEPTION'],
        'live.stream.control.v1' => ['CONTROL_MESSAGE', 'STREAM_END'],
        'live.connector.status.v1' => ['CONNECTED', 'DISCONNECTED', 'ERROR', 'WEBSOCKET_CONNECTED', 'ENTER_ROOM', 'WEBSOCKET_DATA', 'DECODED_DATA', 'RAW_DATA', 'VIEWER_PICKS_UPDATE'],
        'live.room.metadata.v1' => ['ROOM_USER', 'ENTER_ROOM'],
        'live.caption.observed.v1' => ['CAPTION_MESSAGE'],
        'live.ended.v1' => ['CONTROL_MESSAGE', 'STREAM_END', 'DISCONNECTED'],
    ];

    /**
     * @return array<string, list<string>> */
    public function envelopeRules(): array
    {
        return [
            'eventId' => ['required', 'uuid'],
            'eventType' => ['required', 'string', 'in:'.implode(',', array_keys(self::SOURCES))],
            'schemaVersion' => ['required', 'integer', 'in:1'],
            'idempotencyKey' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:tiktok'],
            'connectorKind' => ['required', 'string', 'in:desktop,android'],
            'connectorInstanceId' => ['required', 'uuid'],
            'connectionEpoch' => ['required', 'uuid'],
            'sequence' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'clientSessionId' => ['required', 'uuid'],
            'sourceRoomId' => ['required', 'string', 'max:128'],
            'sourceEventType' => ['required', 'string', 'max:40'],
            'sourceMessageId' => ['nullable', 'string', 'min:1', 'max:128'],
            'sourceKind' => ['required', 'string', 'in:platform,connector,derived'],
            'occurredAt' => ['nullable', ...$this->timestampRules()],
            'occurredAtSource' => ['nullable', 'string', 'in:platform,connector'],
            'observedAt' => ['required', ...$this->timestampRules()],
        ];
    }

    /**
     * @return list<string> */
    private function timestampRules(): array
    {
        return ['string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'];
    }

    /**
     * @return array<string, list<string>> */
    public function payloadRules(string $type): array
    {
        $counter = ['nullable', 'integer', 'min:0', 'max:9007199254740991'];
        $quality = ['required', 'string', 'in:reported,estimated,unknown'];

        return match ($type) {
            'live.started.v1' => ['title' => ['nullable', 'string', 'max:255'], 'creator' => ['nullable', 'string', 'max:255']],
            'live.comment.received.v1' => ['text' => ['required', 'string', 'max:4000'], 'authorId' => ['required', 'string', 'max:128'], 'authorName' => ['present', 'nullable', 'string', 'max:255']],
            'live.audience.sampled.v1' => ['concurrent' => $counter, 'cumulative' => $counter, 'popularity' => $counter, 'quality' => $quality],
            'live.likes.sampled.v1' => ['increment' => $counter, 'total' => $counter, 'quality' => $quality],
            'live.social.action.v1' => ['action' => ['required', 'string', 'in:follow,share,unknown'], 'authorId' => ['nullable', 'string', 'max:128'], 'detail' => ['nullable', 'string', 'max:1000']],
            'live.system.notice.v1' => ['text' => ['required', 'string', 'max:4000'], 'code' => ['nullable', 'string', 'max:128']],
            'live.moderation.observed.v1' => ['text' => ['nullable', 'string', 'max:4000'], 'targetMessageId' => ['nullable', 'string', 'max:128'], 'code' => ['nullable', 'string', 'max:128']],
            'live.stream.control.v1' => ['action' => ['required', 'string', 'in:suspend,resume,stop,end_unverified,end_confirmed,notice'], 'reason' => ['nullable', 'string', 'max:1000']],
            'live.connector.status.v1' => ['state' => ['required', 'string', 'in:connecting,connected,tracking,reconnecting,disconnected,error,suspended,stopped,ended_unverified'], 'reason' => ['nullable', 'string', 'max:1000'], 'bytesObserved' => $counter, 'messagesObserved' => $counter],
            'live.room.metadata.v1' => ['title' => ['required', 'string', 'max:255'], 'creator' => ['nullable', 'string', 'max:255']],
            'live.caption.observed.v1' => [
                'segments' => ['required', 'array', 'list', 'min:1', 'max:20'],
                'segments.*' => ['required', 'array:text,startMs,endMs'],
                'segments.*.text' => ['required', 'string', 'max:1000'],
                'segments.*.startMs' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
                'segments.*.endMs' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            ],
            'live.ended.v1' => ['kind' => ['required', 'string', 'in:confirmed,unverified,manual,suspended'], 'reason' => ['nullable', 'string', 'max:1000']],
            default => [],
        };
    }

    /**
     * @return array<string, list<string>> */
    public function rules(string $type): array
    {
        $payload = $this->payloadRules($type);
        $keys = array_filter(array_keys($payload), fn (string $key): bool => ! str_contains($key, '.'));
        $rules = $this->envelopeRules();
        $rules['eventType'] = ['required', 'string', 'in:'.$type];
        $rules['sourceEventType'] = ['required', 'string', 'in:'.implode(',', self::SOURCES[$type] ?? [])];
        $rules['payload'] = ['required', 'array:'.implode(',', $keys)];
        foreach ($payload as $field => $fieldRules) {
            $rules['payload.'.$field] = $fieldRules;
        }

        return $rules;
    }

    /** @param array<string, mixed> $event
     * @return array<string, mixed> */
    public function validate(array $event): array
    {
        $rules = $this->rules(is_string($event['eventType'] ?? null) ? $event['eventType'] : '');
        $wrapped = ['event' => ['required', 'array:'.implode(',', array_keys($this->envelopeRules())).',payload']];
        foreach ($rules as $field => $fieldRules) {
            $wrapped['event.'.$field] = $fieldRules;
        }
        $validated = Validator::make(['event' => $event], $wrapped)->validate()['event'];
        $payload = $validated['payload'];
        $invalid = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);
        if (isset($validated['occurredAt']) !== isset($validated['occurredAtSource'])) {
            $invalid('occurredAtSource', 'occurredAt and its provenance must be supplied together.');
        }
        if ($validated['eventType'] === 'live.likes.sampled.v1' && ! isset($payload['increment']) && ! isset($payload['total'])) {
            $invalid('payload', 'A reported increment or total is required.');
        }
        if ($validated['eventType'] === 'live.audience.sampled.v1' && ! isset($payload['concurrent']) && ! isset($payload['cumulative']) && ! isset($payload['popularity'])) {
            $invalid('payload', 'At least one audience field is required.');
        }
        if ($validated['eventType'] === 'live.social.action.v1' && in_array($validated['sourceEventType'], ['FOLLOW', 'SHARE'], true)
            && strtolower($validated['sourceEventType']) !== $payload['action']) {
            $invalid('payload.action', 'The action must match its source.');
        }
        if (($payload['action'] ?? null) === 'end_confirmed' || ($payload['kind'] ?? null) === 'confirmed') {
            if ($validated['sourceKind'] !== 'platform' || ! in_array($validated['sourceEventType'], ['CONTROL_MESSAGE', 'STREAM_END'], true)) {
                $invalid('payload', 'Confirmed end requires a platform control observation.');
            }
        }
        foreach ($payload['segments'] ?? [] as $segment) {
            if ($segment['endMs'] < $segment['startMs']) {
                $invalid('payload.segments', 'Segment end must not precede start.');
            }
        }

        return $validated;
    }

    /** @param array<string, mixed> $event */
    public function fingerprint(array $event): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return hash('sha256', json_encode($sort($event), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
