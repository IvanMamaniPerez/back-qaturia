<?php

return [
    'captions_enabled' => (bool) env('LIVE_CAPTIONS_ENABLED', false),
    'viewer_picks_enabled' => (bool) env('LIVE_VIEWER_PICKS_ENABLED', false),
    'max_batch_bytes' => 1048576,
    'max_event_bytes' => 16384,
    'requests_per_minute' => 120,
];
