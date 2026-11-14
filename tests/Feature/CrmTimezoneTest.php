<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmPermissionsSeeder;
use VentureDrake\LaravelCrm\Jobs\MaterialiseSmsCampaignRecipients;
use VentureDrake\LaravelCrm\Livewire\Calls\CallItem;
use VentureDrake\LaravelCrm\Livewire\Dashboard;
use VentureDrake\LaravelCrm\Livewire\Lunches\LunchItem;
use VentureDrake\LaravelCrm\Livewire\Meetings\MeetingItem;
use VentureDrake\LaravelCrm\Livewire\Notes\NoteItem;
use VentureDrake\LaravelCrm\Livewire\Tasks\TaskEdit;
use VentureDrake\LaravelCrm\Models\Call;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Lunch;
use VentureDrake\LaravelCrm\Models\Meeting;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Models\SmsCampaign;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrm\Notifications\TaskReminderNotification;
use VentureDrake\LaravelCrm\Services\EmailCampaignService;
use VentureDrake\LaravelCrm\Services\SmsCampaignService;
use VentureDrake\LaravelCrm\Services\TaskService;
use VentureDrake\LaravelCrm\Support\CrmDateTime;

beforeEach(function () {
    config(['laravel-crm.teams' => false, 'permission.teams' => false]);
    Setting::create(['name' => 'timezone', 'value' => 'America/Lima', 'global' => true]);
});

test('task forms round trip regional input through UTC persistence and editing', function () {
    $task = app(TaskService::class)->create(new Request(['name' => 'Local appointment', 'start_at' => '2026-11-14T10:00', 'due_at' => '2026-11-14T11:00']));
    $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'start_at' => '2026-11-14 15:00:00', 'due_at' => '2026-11-14 16:00:00']);
    expect($task->fresh()->toArray()['start_at'])->toBe('2026-11-14T15:00:00.000000Z');
    $editor = new TaskEdit;
    $editor->mount($task->fresh());
    expect($editor->start_at)->toBe('2026-11-14T10:00');
    app(TaskService::class)->update(new Request(['name' => 'Local appointment', 'start_at' => $editor->start_at, 'due_at' => '2026-11-14T23:30']), $task);
    $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'start_at' => '2026-11-14 15:00:00', 'due_at' => '2026-11-15 04:30:00']);
});

test('related activity editing displays local time and writes UTC exactly once', function (string $model, string $component, string $parameter) {
    $this->seed(LaravelCrmPermissionsSeeder::class);
    $user = User::factory()->create(['crm_access' => true]);
    $user->assignRole('Owner');
    $record = $model::create(['name' => 'Appointment', 'start_at' => '2026-11-14T15:00:00Z', 'finish_at' => '2026-11-14T16:00:00Z']);
    Livewire::actingAs($user)->test($component, [$parameter => $record])
        ->assertSet('start_at', '2026-11-14T10:00')->assertSee('10:00 AM')
        ->set('start_at', '2026-11-14T12:00')->set('finish_at', '2026-11-14T13:00')
        ->call('update')->assertHasNoErrors();
    $this->assertDatabaseHas((new $model)->getTable(), ['id' => $record->id, 'start_at' => '2026-11-14 17:00:00', 'finish_at' => '2026-11-14 18:00:00']);
})->with([
    'call' => [Call::class, CallItem::class, 'call'],
    'meeting' => [Meeting::class, MeetingItem::class, 'meeting'],
    'lunch' => [Lunch::class, LunchItem::class, 'lunch'],
]);

test('offset input and DateTime objects are normalized without shifting twice', function () {
    $task = Task::create(['name' => 'Offset instant', 'due_at' => '2026-11-14T10:00:00-05:00', 'created_at' => Carbon::parse('2026-11-14 10:00:00', 'America/Lima')]);
    $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'due_at' => '2026-11-14 15:00:00', 'created_at' => '2026-11-14 15:00:00']);
    $utc = $task->fresh()->due_at;
    expect(CrmDateTime::local($utc)->format('H:i'))->toBe('10:00');
    expect($utc->format('H:i'))->toBe('15:00');
    expect($utc->timezoneName)->toBe('UTC');
    expect(config('app.timezone'))->toBe('UTC');
    expect(date_default_timezone_get())->toBe('UTC');
    $task->update(['created_at' => '2026-11-14T12:00:00-05:00']);
    $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'created_at' => '2026-11-14 17:00:00']);
});

test('civil dates retain their calendar day across regional midnight', function () {
    $this->travelTo(Carbon::parse('2026-11-15T02:00:00Z'));
    $invoice = Invoice::create(['issue_date' => '2026-11-14', 'due_date' => '2026-11-14']);
    expect(Invoice::whereKey($invoice->id)->whereDate('due_date', '2026-11-14')->exists())->toBeTrue();
    expect($invoice->fresh()->due_date->format('Y-m-d'))->toBe('2026-11-14');
    expect(CrmDateTime::civil($invoice->due_date)->isToday())->toBeTrue();
    expect(CrmDateTime::civil($invoice->due_date)->lt(CrmDateTime::today()))->toBeFalse();
});

test('invalid and ambiguous local times are rejected before persistence', function (string $zone, string $value) {
    Setting::where('name', 'timezone')->firstOrFail()->update(['value' => $zone]);
    expect(fn () => CrmDateTime::fromLocal($value, 'start_at'))->toThrow(ValidationException::class);
})->with([
    'spring gap' => ['America/New_York', '2026-03-08T02:30'],
    'autumn repeated time' => ['America/New_York', '2026-11-01T01:30'],
    'invalid calendar day' => ['America/Lima', '2026-02-30T10:00'],
    'invalid calendar day with offset' => ['America/Lima', '2026-02-30T10:00:00-05:00'],
    'invalid text with offset' => ['America/Lima', 'invalid-05:00'],
    'invalid text' => ['America/Lima', 'tomorrow at noon'],
]);

test('explicit offsets disambiguate both occurrences of an autumn time', function () {
    Setting::where('name', 'timezone')->firstOrFail()->update(['value' => 'America/New_York']);
    expect(CrmDateTime::fromLocal('2026-11-01T01:30:00-04:00')->format('H:i'))->toBe('05:30');
    expect(CrmDateTime::fromLocal('2026-11-01T01:30:00-05:00')->format('H:i'))->toBe('06:30');
});

test('clearing task scheduling keeps null rather than converting it to the current time', function () {
    $task = app(TaskService::class)->create(new Request(['name' => 'Unscheduled', 'start_at' => '', 'due_at' => null]));
    $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'start_at' => null, 'due_at' => null]);
    expect(CrmDateTime::local(null))->toBeNull();
});

test('note editing preserves the configured local hour through a UTC round trip', function () {
    $this->seed(LaravelCrmPermissionsSeeder::class);
    $user = User::factory()->create(['crm_access' => true]);
    $user->assignRole('Owner');
    $lead = Lead::create(['title' => 'Note owner']);
    $note = $lead->notes()->create(['external_id' => (string) Str::uuid(), 'user_created_id' => $user->id, 'content' => 'Regional note', 'noted_at' => '2026-11-14T15:00:00Z']);

    Livewire::actingAs($user)->test(NoteItem::class, ['note' => $note])
        ->assertSet('noted_at', '2026-11-14 10:00:00')
        ->set('noted_at', '2026-11-14 12:00:00')
        ->call('update')->assertHasNoErrors();

    $this->assertDatabaseHas('crm_notes', ['id' => $note->id, 'noted_at' => '2026-11-14 17:00:00']);
});

test('dashboard today filters local calendar boundaries rather than UTC midnight', function () {
    $this->travelTo(Carbon::parse('2026-11-15T02:00:00Z'));
    foreach (['2026-11-14T04:59:59Z', '2026-11-14T05:00:00Z', '2026-11-15T01:00:00Z', '2026-11-15T05:00:00Z'] as $date) {
        Lead::create(['title' => 'Boundary', 'created_at' => $date]);
    }
    $dashboard = new Dashboard;
    $dashboard->period = 'today';
    expect($dashboard->getTotalLeadsCountProperty())->toBe(2);
});

test('calendar buckets span DST days using local dates instead of fixed seconds', function () {
    Setting::where('name', 'timezone')->firstOrFail()->update(['value' => 'America/New_York']);
    $start = Carbon::parse('2026-03-08 00:00:00', 'America/New_York');
    expect(CrmDateTime::databaseRange($start, $start->copy()->endOfDay()))->toBe(['2026-03-08 05:00:00.000000', '2026-03-09 03:59:59.999999']);
    expect(CrmDateTime::bucketKey(Carbon::parse('2026-03-09T04:30:00Z'), $start, 'day'))->toBe(Carbon::parse('2026-03-09T04:00:00Z')->getTimestamp());
});

test('last month remains February when the regional current day is March thirty first', function () {
    $this->travelTo(Carbon::parse('2026-03-31T15:00:00Z'));
    foreach (['2026-02-15T15:00:00Z', '2026-03-15T15:00:00Z'] as $instant) {
        Lead::create(['title' => 'Calendar month', 'created_at' => $instant]);
    }
    $dashboard = new Dashboard;
    $dashboard->period = 'last_month';
    expect($dashboard->getTotalLeadsCountProperty())->toBe(1);
});

test('dashboard distinguishes the repeated hour on a twenty five hour regional day', function () {
    Setting::where('name', 'timezone')->firstOrFail()->update(['value' => 'America/New_York']);
    $this->travelTo(Carbon::parse('2026-11-02T02:00:00Z'));
    foreach (['2026-11-01T05:30:00Z', '2026-11-01T06:30:00Z'] as $instant) {
        Lead::create(['title' => 'Repeated hour', 'created_at' => $instant]);
    }
    $dashboard = new class extends Dashboard
    {
        public function hourlyLeads(): array
        {
            return array_combine($this->getDateLabels(), $this->groupByGranularity(Lead::query()));
        }
    };
    $dashboard->period = 'today';
    $hours = $dashboard->hourlyLeads();
    expect($hours)->toHaveCount(25);
    expect($hours['01:00 -04:00'])->toBe(1);
    expect($hours['01:00 -05:00'])->toBe(1);
    expect(array_sum($hours))->toBe(2);
});

test('campaign scheduling compares future times in the configured zone and persists UTC', function (string $campaignClass, string $serviceClass) {
    Queue::fake([MaterialiseSmsCampaignRecipients::class]);
    $this->travelTo(Carbon::parse('2026-11-14T14:00:00Z'));
    $attributes = ['name' => 'Local schedule', 'body' => 'Hello', 'status' => 'draft'];
    if ($campaignClass === EmailCampaign::class) {
        $attributes['subject'] = 'Scheduled hello';
    }
    $campaign = $campaignClass::create($attributes);
    app($serviceClass)->schedule($campaign, '2026-11-14T10:00');
    $this->assertDatabaseHas($campaign->getTable(), ['id' => $campaign->id, 'scheduled_at' => '2026-11-14 15:00:00', 'timezone' => 'America/Lima']);
    expect($campaign->fresh()->toArray()['scheduled_at'])->toBe('2026-11-14T15:00:00.000000Z');
    if ($campaignClass === SmsCampaign::class) {
        Queue::assertPushed(MaterialiseSmsCampaignRecipients::class);
    } else {
        Queue::assertNothingPushed();
    }
})->with([
    'email' => [EmailCampaign::class, EmailCampaignService::class],
    'sms' => [SmsCampaign::class, SmsCampaignService::class],
]);

test('past scheduled times fail in the regional zone', function () {
    $this->travelTo(Carbon::parse('2026-11-14T15:00:00Z'));
    expect(fn () => CrmDateTime::futureLocal('2026-11-14T09:30'))->toThrow(ValidationException::class);
});

test('reminders query UTC and notification text uses the regional time', function () {
    Notification::fake([TaskReminderNotification::class]);
    $this->travelTo(Carbon::parse('2026-11-14T15:00:00Z'));
    $user = User::factory()->create();
    $task = Task::create(['name' => 'Reminder', 'due_at' => '2026-11-14T15:10:00Z', 'user_assigned_id' => $user->id]);
    $later = Task::create(['name' => 'Later', 'due_at' => '2026-11-14T18:00:00Z', 'user_assigned_id' => $user->id]);
    $this->artisan('laravelcrm:reminders')->assertSuccessful();
    Notification::assertSentToTimes($user, TaskReminderNotification::class, 1);
    expect((new TaskReminderNotification($task, $user))->toMail($user)->subject)->toContain('10:10 AM');
    expect((bool) $task->fresh()->reminder_email)->toBeTrue();
    expect((bool) $later->fresh()->reminder_email)->toBeFalse();
});

test('changing the CRM zone does not leak a global timezone or a stale display zone', function () {
    $utc = Carbon::parse('2026-11-14T15:00:00Z');
    expect(CrmDateTime::local($utc)->format('H:i'))->toBe('10:00');
    Setting::where('name', 'timezone')->firstOrFail()->update(['value' => 'Asia/Tokyo']);
    expect(CrmDateTime::local($utc)->format('Y-m-d H:i'))->toBe('2026-11-15 00:00');
    expect(date_default_timezone_get())->toBe('UTC');
    expect($utc->getOffset())->toBe(0);
});
