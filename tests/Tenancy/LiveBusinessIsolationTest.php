<?php

use App\Models\LiveConnector;
use App\Models\User;
use App\Services\ApiTokenSessionService;
use App\Services\LiveAccessService;
use Database\Seeders\LivePermissionsSeeder;
use Tests\BusinessTestCase;
use VentureDrake\LaravelCrm\Support\BusinessContext;

uses(BusinessTestCase::class);

test('LIVE context restores company identity between bearer requests and permission grants remain explicit', function () {
    $one = LiveConnector::factory()->create();
    $two = LiveConnector::factory()->create();
    $a = User::withoutGlobalScopes()->findOrFail($one->user_id);
    $b = User::withoutGlobalScopes()->findOrFail($two->user_id);
    $member = User::factory()->create(['team_id' => $a->team_id, 'crm_access' => true]);
    $this->seed(LivePermissionsSeeder::class);
    app(BusinessContext::class)->run($a->team_id, fn () => $member->givePermissionTo('live.read'));
    expect(app(BusinessContext::class)->run($a->team_id, fn () => app(LiveAccessService::class)->allows($member, 'live.read')))->toBeTrue();
    expect(app(BusinessContext::class)->run($a->team_id, fn () => app(LiveAccessService::class)->allows($member, 'live.ingest')))->toBeFalse();
    $tokenA = app(ApiTokenSessionService::class)->issue($a, 'one')['access_token'];
    $tokenB = app(ApiTokenSessionService::class)->issue($b, 'two')['access_token'];
    $this->withToken($tokenA)->getJson('/api/v1/live/sessions')->assertOk()->assertJsonPath('data.0.id', $one->live_session_id)->assertJsonCount(1, 'data');
    app('auth')->forgetGuards();
    $this->withToken($tokenB)->getJson('/api/v1/live/sessions')->assertOk()->assertJsonPath('data.0.id', $two->live_session_id)->assertJsonCount(1, 'data');
    app('auth')->forgetGuards();
    expect(app(BusinessContext::class)->id())->toBeNull();
    $this->withHeader('X-Team-ID', $a->team_id)->getJson('/api/v1/live/sessions')->assertForbidden();
});
