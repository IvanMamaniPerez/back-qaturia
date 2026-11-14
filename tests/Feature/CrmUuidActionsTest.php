<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmPermissionsSeeder;
use VentureDrake\LaravelCrm\Livewire\Deals\DealIndex;
use VentureDrake\LaravelCrm\Livewire\Quotes\QuoteIndex;
use VentureDrake\LaravelCrm\Livewire\Tasks\TaskIndex;
use VentureDrake\LaravelCrm\Livewire\Teams\TeamIndex;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrm\Models\Team;

beforeEach(function () {
    config(['laravel-crm.teams' => false, 'permission.teams' => false]);
});

test('the task list renders a string UUID and completes exactly that task', function () {
    $this->seed(LaravelCrmPermissionsSeeder::class);
    $user = User::factory()->create(['crm_access' => true]);
    $user->assignRole('Owner');
    $this->travelTo(Carbon::parse('2026-11-14T15:00:00Z'));
    $task = Task::create(['name' => 'UUID completion', 'user_owner_id' => $user->id]);
    $other = Task::create(['name' => 'Leave pending', 'user_owner_id' => $user->id]);

    $component = Livewire::actingAs($user)->test(TaskIndex::class);
    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $actions = [];
    foreach ($document->getElementsByTagName('button') as $button) {
        if (str_starts_with($button->getAttribute('wire:click'), 'complete(')) {
            $actions[] = $button->getAttribute('wire:click');
        }
    }
    expect($actions)->toContain('complete(\''.$task->id.'\')');
    $component->call('complete', $task->id)->assertHasNoErrors();

    $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'completed_at' => '2026-11-14 15:00:00']);
    $this->assertDatabaseHas('crm_tasks', ['id' => $other->id, 'completed_at' => null]);
});

test('the shared deletion dialog keeps UUIDs and special characters inside a string parameter', function (string $id, string $expression) {
    $html = Blade::render('<x-crm-delete-confirm model="task" :id="$id" />', ['id' => $id]);
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $button = (new DOMXPath($document))->query('//button[@*[name()="wire:click"]]')->item(0);

    expect($button->getAttribute('wire:click'))->toBe($expression);
    expect($document->getElementsByTagName('dialog')->item(0)->getAttribute('id'))->toBe('modalDeleteTask'.$id);
    expect($document->getElementsByTagName('script'))->toHaveCount(0);
})->with([
    'uuid' => ['01a1dae3-5fcb-72a6-976e-50765a277ab4', "delete('01a1dae3-5fcb-72a6-976e-50765a277ab4')"],
    'escaped text' => ["untrusted');alert(1);//<script>", "delete('untrusted\\u0027);alert(1);\\/\\/\\u003Cscript\\u003E')"],
]);

test('commercial action buttons retain their UUID through rendering and status changes', function (string $modelClass, string $componentClass, string $action, string $field) {
    $this->seed(LaravelCrmPermissionsSeeder::class);
    $user = User::factory()->create(['crm_access' => true]);
    $user->assignRole('Owner');
    $pipeline = Pipeline::create(['name' => 'UUID actions', 'model' => $modelClass]);
    $this->travelTo(Carbon::parse('2026-11-14T15:00:00Z'));
    $record = $modelClass::create(['title' => 'UUID status', 'user_owner_id' => $user->id]);
    $component = Livewire::actingAs($user)->test($componentClass);
    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $actions = [];
    foreach ($document->getElementsByTagName('*') as $element) {
        if ($element->hasAttribute('wire:click')) {
            $actions[] = $element->getAttribute('wire:click');
        }
    }

    expect($actions)->toContain($action.'(\''.$record->id.'\')');
    $component->call($action, $record->id)->assertHasNoErrors();

    $this->assertDatabaseHas($record->getTable(), ['id' => $record->id, $field => '2026-11-14 15:00:00']);
})->with([
    'deal won' => [Deal::class, DealIndex::class, 'won', 'closed_at'],
    'quote accepted' => [Quote::class, QuoteIndex::class, 'accept', 'accepted_at'],
]);

test('a user without edit permission cannot complete a task by supplying its UUID', function () {
    $this->seed(LaravelCrmPermissionsSeeder::class);
    $user = User::factory()->create(['crm_access' => true]);
    $user->givePermissionTo('view crm tasks');
    $task = Task::create(['name' => 'Protected task']);

    Livewire::actingAs($user)->test(TaskIndex::class)
        ->call('complete', $task->id)->assertForbidden();

    $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'completed_at' => null]);
});

test('team deletion targets its own UUID dialog and the owner filter binds to a real property', function () {
    $this->seed(LaravelCrmPermissionsSeeder::class);
    $user = User::factory()->create(['crm_access' => true]);
    $user->assignRole('Owner');
    $other = User::factory()->create();
    $team = Team::create(['name' => 'Selected UUID team', 'user_id' => $user->id]);
    Team::create(['name' => 'Other UUID team', 'user_id' => $other->id]);

    $component = Livewire::actingAs($user)->test(TeamIndex::class)
        ->set('user_id', [$user->id])
        ->assertSee('Selected UUID team')->assertDontSee('Other UUID team');
    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $openers = [];
    foreach ($document->getElementsByTagName('button') as $button) {
        if ($button->hasAttribute('onclick')) {
            $openers[] = $button->getAttribute('onclick');
        }
    }

    expect($openers)->toContain('document.getElementById(\'modalDeleteTeam'.$team->id.'\').showModal()');
    expect($document->getElementById('modalDeleteTeam'.$team->id))->not->toBeNull();
    expect($component->html())->not->toContain('wire:model.live="team_id"');
});
