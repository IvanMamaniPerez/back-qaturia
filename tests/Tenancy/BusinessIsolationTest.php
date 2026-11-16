<?php

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\BusinessTestCase;
use VentureDrake\LaravelCrm\Console\LaravelCrmUpdate;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmBootstrapSeeder;
use VentureDrake\LaravelCrm\Livewire\Auth\Register;
use VentureDrake\LaravelCrm\Livewire\Portal\Features\PublicFeatureBoard;
use VentureDrake\LaravelCrm\Livewire\Portal\Features\PublicFeatureShow;
use VentureDrake\LaravelCrm\Livewire\Settings\Features\FeatureStatusIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\SettingEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\TaxRates\TaxRateCreate;
use VentureDrake\LaravelCrm\Livewire\Users\UserImport;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrm\Models\EmailCampaignClick;
use VentureDrake\LaravelCrm\Models\EmailCampaignRecipient;
use VentureDrake\LaravelCrm\Models\Feature;
use VentureDrake\LaravelCrm\Models\FeatureStatus;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrm\Models\Team;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrm\Services\BusinessRegistrationService;
use VentureDrake\LaravelCrm\Services\FeatureService;
use VentureDrake\LaravelCrm\Services\NumberGeneratorService;
use VentureDrake\LaravelCrm\Support\BusinessContext;
use VentureDrake\LaravelCrm\Support\PdfContactDetails;

uses(BusinessTestCase::class);

function newBusinessOwner(string $name): User
{
    return app(BusinessRegistrationService::class)->register([
        'name' => $name.' owner', 'email' => strtolower($name).'@example.test',
        'password' => 'safe-test-password', 'business_name' => $name,
        'legal_name' => $name.' SAC', 'tax_id' => 'RUC-'.$name,
    ]);
}

beforeEach(function () {
    $this->seed(LaravelCrmBootstrapSeeder::class);
});

test('registration creates two companies with the same global owner role and distinct assignments', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    expect($a->team_id)->not->toBe($b->team_id)
        ->and(Role::count())->toBe(4)
        ->and(Role::whereNotNull('team_id')->count())->toBe(0)
        ->and($a->business->legal_name)->toBe('Alpha SAC')
        ->and($b->business->tax_id)->toBe('RUC-Beta');
    expect(DB::table('model_has_roles')->where('team_id', $a->team_id)->value('role_id'))
        ->toBe(DB::table('model_has_roles')->where('team_id', $b->team_id)->value('role_id'));
    expect(app(BusinessContext::class)->id())->toBeNull();
});

test('registration rolls back both business and identity if role assignment fails', function () {
    Role::where('name', 'Owner')->delete();
    try {
        newBusinessOwner('Rollback');
        $this->fail('The missing role must abort registration.');
    } catch (ModelNotFoundException) {
        expect(Business::count())->toBe(0)->and(User::count())->toBe(0);
    }
});

test('scopes fail closed without a business and isolate web staff and background contexts', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $context = app(BusinessContext::class);
    $first = $context->run($a->team_id, fn () => Organization::create(['name' => 'A customer']));
    $second = $context->run($b->team_id, fn () => Organization::create(['name' => 'B customer']));
    expect(Organization::count())->toBe(0);
    $this->actingAs($a);
    expect(Organization::pluck('id')->all())->toBe([$first->id])
        ->and(Organization::find($second->id))->toBeNull()
        ->and(Gate::allows('view', $second))->toBeFalse();
    $context->run($b->team_id, fn () => expect(Organization::pluck('id')->all())->toBe([$second->id]));
    expect(Organization::pluck('id')->all())->toBe([$first->id]);
});

test('foreign business IDs and cross-company references are rejected before persistence', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $context = app(BusinessContext::class);
    $foreign = $context->run($b->team_id, fn () => Organization::create(['name' => 'Other']));
    $context->run($a->team_id, function () use ($b, $foreign) {
        expect(fn () => Organization::create(['name' => 'Forged', 'team_id' => $b->team_id]))
            ->toThrow(ValidationException::class);
        expect(fn () => Person::create(['first_name' => 'Forged', 'organization_id' => $foreign->id]))
            ->toThrow(ValidationException::class);
    });
});

test('database constraints also reject bulk cross-business references', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $context = app(BusinessContext::class);
    $foreign = $context->run($b->team_id, fn () => Organization::create(['name' => 'Other']));
    $person = $context->run($a->team_id, fn () => Person::create(['first_name' => 'Local']));
    expect(fn () => DB::table('crm_people')->where('id', $person->id)->update(['organization_id' => $foreign->id]))
        ->toThrow(QueryException::class);
});

test('business identity is never inherited from global settings or another company', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $context = app(BusinessContext::class);
    $context->run($a->team_id, function () {
        $settings = app('laravel-crm.settings');
        $settings->set('vat_number', 'ALPHA-TAX');
        $settings->set('legal_name', 'Alpha Legal');
        $settings->set('invoice_terms', 'Alpha only');
        $settings->set('currency', 'PEN');
        $settings->set('tax_rate', 18);
        expect($settings->get('vat_number'))->toBe('ALPHA-TAX')
            ->and($settings->first('team'))->toBeInstanceOf(Business::class)
            ->and(Setting::currency()->value)->toBe('PEN')
            ->and(Setting::where('name', 'tax_rate')->firstOrFail()->value)->toBe('18');
    });
    $context->run($b->team_id, function () {
        $settings = app('laravel-crm.settings');
        expect($settings->get('vat_number'))->toBe('RUC-Beta')
            ->and(Setting::currency()->value)->not->toBe('PEN')
            ->and($settings->get('invoice_terms'))->toBeNull();
        $settings->set('vat_number', null);
        expect($settings->get('vat_number'))->toBeNull();
    });
    expect(DB::table('crm_settings')->whereIn('name', ['vat_number', 'legal_name'])->count())->toBe(0);
});

test('internal groups never change membership or duplicate roles and cannot attach other business users', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    app(BusinessContext::class)->run($a->team_id, function () use ($a, $b) {
        $team = Team::create(['name' => 'Sales', 'user_id' => $a->id]);
        $team->users()->sync([$a->id]);
        expect(fn () => $team->users()->sync([$b->id]))->toThrow(ValidationException::class);
        expect(Role::count())->toBe(4)->and($a->fresh()->team_id)->toBe($a->team_id);
    });
});

test('owners cannot mutate the global permission catalog or deactivate the owner account', function () {
    $a = newBusinessOwner('Alpha');
    $this->actingAs($a);
    app(BusinessContext::class)->set($a->team_id);
    $role = Role::where('name', 'Employee')->firstOrFail();
    expect(Gate::allows('update', $role))->toBeFalse()
        ->and(Gate::allows('create', Role::class))->toBeFalse()
        ->and(fn () => $a->forceFill(['crm_access' => false])->save())->toThrow(ValidationException::class)
        ->and(fn () => $a->removeRole('Owner'))->toThrow(ValidationException::class)
        ->and(fn () => $a->delete())->toThrow(ValidationException::class);
});

test('changing company catalog defaults never mutates shared or foreign defaults', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $context = app(BusinessContext::class);
    $shared = $context->system(fn () => TaxRate::create([
        'name' => 'Shared tax', 'rate' => 5, 'default' => true,
    ]));
    $other = $context->run($b->team_id, fn () => TaxRate::create([
        'name' => 'Beta tax', 'rate' => 10, 'default' => true,
    ]));
    $this->actingAs($a);
    $context->set($a->team_id);
    Livewire::test(TaxRateCreate::class)
        ->set('name', 'Alpha tax')->set('rate', 18)->set('default', true)
        ->call('save')->assertHasNoErrors();
    expect(TaxRate::where('default', true)->firstOrFail()->name)->toBe('Alpha tax')
        ->and(DB::table('crm_tax_rates')->where('id', $shared->id)->value('default'))->toBeTruthy()
        ->and(DB::table('crm_tax_rates')->where('id', $other->id)->value('default'))->toBeTruthy();

    $status = FeatureStatus::whereNull('team_id')->firstOrFail();
    $originalOrder = $status->order;
    Livewire::test(FeatureStatusIndex::class)
        ->call('reorder', [$status->id]);
    expect($status->fresh()->order)->toBe($originalOrder);
});

test('counter reservations are partitioned by business', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $context = app(BusinessContext::class);
    $first = $context->run($a->team_id, fn () => NumberGeneratorService::next(Lead::class));
    $again = $context->run($a->team_id, fn () => NumberGeneratorService::next(Lead::class));
    $other = $context->run($b->team_id, fn () => NumberGeneratorService::next(Lead::class));
    expect($first)->toBe($other)->and($again)->toBe($first + 1);
});

test('installation updates preserve businesses and backfill UUID records with each company prefix', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $context = app(BusinessContext::class);
    $settings = app('laravel-crm.settings');
    $leads = [];
    foreach ([$a, $b] as $owner) {
        $leads[] = $context->run($owner->team_id, function () use ($owner, $settings) {
            $settings->set('lead_prefix', $owner->business->name.'-');
            Team::create(['name' => 'Sales', 'user_id' => $owner->id]);

            return Lead::create(['title' => 'Existing lead']);
        });
    }
    DB::table('crm_leads')->update(['number' => null]);
    $settings->setInstallWide('db_update_1200', 0);
    $settings->setInstallWide('db_update_1201', 0);

    // Asset publishing is outside this database test and would modify the
    // workspace. All migrations, seeders and backfills run normally.
    $command = new class($settings) extends LaravelCrmUpdate
    {
        public function call($command, array $arguments = [])
        {
            return $command === 'laravelcrm:upgrade' ? self::SUCCESS : parent::call($command, $arguments);
        }
    };
    Artisan::registerCommand($command);
    $this->artisan('laravelcrm:update', ['--force' => true])->assertSuccessful();
    $this->artisan('laravelcrm:update', ['--force' => true])->assertSuccessful();

    foreach ($leads as $index => $lead) {
        $owner = [$a, $b][$index];
        $context->run($owner->team_id, function () use ($lead, $owner) {
            expect($lead->fresh()->prefix)->toBe($owner->business->name.'-')
                ->and($lead->fresh()->number)->not->toBeNull();
        });
    }
    expect(Business::count())->toBe(2)->and(Role::count())->toBe(4)
        ->and(DB::table('crm_pipelines')->whereNotNull('team_id')->count())->toBe(0)
        ->and($settings->getInstallWide('db_update_1201'))->toBe('1')
        ->and($context->id())->toBeNull();
});

test('API rejects selecting another company and resets context after the request', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    Sanctum::actingAs($a);
    $this->getJson('/crm/api/v2/people', ['X-Team-ID' => $b->team_id])->assertForbidden();
    $this->getJson('/crm/api/v2/people', ['X-Team-ID' => $a->team_id])->assertOk();
});

test('CRM accounts without a business fail closed while public registration is reachable', function () {
    $this->get(route('laravel-crm.register'))->assertOk();
    $legacy = User::factory()->create(['crm_access' => true]);
    $this->actingAs($legacy)->get(route('laravel-crm.dashboard'))->assertForbidden();
});

test('public roadmaps keep their own context on Livewire updates and exclude private or deleted records', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $context = app(BusinessContext::class);
    $context->run($a->team_id, fn () => Feature::create(['title' => 'Alpha board', 'is_public' => true]));
    $feature = $context->run($b->team_id, function () use ($b) {
        $feature = Feature::create(['title' => 'Beta board', 'is_public' => true]);
        Feature::create(['title' => 'Beta private', 'is_public' => false]);
        $deleted = Feature::create(['title' => 'Beta deleted', 'is_public' => true]);
        $deleted->delete();
        app(FeatureService::class)->comment($feature, $b, 'Beta public comment');

        return $feature;
    });
    $this->actingAs($a);
    Livewire::test(PublicFeatureBoard::class, ['portalTeamId' => $b->team_id])
        ->assertSee('Beta board')->assertDontSee('Alpha board')->assertDontSee('Beta private')->assertDontSee('Beta deleted')
        ->set('sort', 'newest')->assertSee('Beta board')->assertDontSee('Alpha board');
    Livewire::test(PublicFeatureShow::class, ['feature' => $feature])
        ->assertSee('Beta public comment')->call('$refresh')->assertSee('Beta public comment');
    expect($context->id())->toBe($a->team_id);
});

test('imported collaborators receive the company and global role and skip an identity from another company', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $this->actingAs($a);
    app(BusinessContext::class)->set($a->team_id);
    Queue::fake();
    session()->put('crm_user_import_preview', array_map(fn ($row) => $row + [
        'email_verified_at' => '', 'created_at' => '', 'updated_at' => '', 'last_online_at' => '',
    ], [
        ['row' => 2, 'name' => 'Imported', 'email' => 'imported@example.test', 'crm_access' => true, 'role' => 'Employee'],
        ['row' => 3, 'name' => 'Duplicate', 'email' => $b->email, 'crm_access' => true, 'role' => 'Employee'],
    ]));
    Livewire::test(UserImport::class)
        ->call('startImport')->call('processNextChunk')
        ->assertSet('importedCount', 1)->assertSet('skippedCount', 1);
    $imported = User::where('email', 'imported@example.test')->firstOrFail();
    expect($imported->team_id)->toBe($a->team_id)->and($imported->hasRole('Employee'))->toBeTrue()
        ->and($b->fresh()->team_id)->toBe($b->team_id);
});

test('business profile writes through Livewire and includes fiscal identity in document contact details', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $this->actingAs($a);
    app(BusinessContext::class)->set($a->team_id);
    Livewire::test(SettingEdit::class)
        ->set('legalName', 'Alpha Legal SAC')
        ->set('vatNumber', '20123456789')
        ->set('fiscalAddress', 'Lima, Peru')
        ->call('save')->assertHasNoErrors();
    expect($a->business->fresh()->legal_name)->toBe('Alpha Legal SAC')
        ->and($b->business->fresh()->tax_id)->toBe('RUC-Beta');
    expect(PdfContactDetails::for('invoice'))
        ->toContain('Alpha Legal SAC', '20123456789', 'Lima, Peru');
});

test('a signed invoice uses its own business even if a different company staff member is logged in', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $invoice = app(BusinessContext::class)->run($b->team_id, fn () => Invoice::create([]));
    $url = URL::signedRoute('laravel-crm.portal.invoices.show', ['invoice' => $invoice->external_id]);
    $this->actingAs($a)->get($url)->assertOk()->assertSee('Beta')->assertDontSee('Alpha SAC');
    $this->get($url.'&forged=1')->assertUnauthorized();
    $this->get(route('laravel-crm.invoices.show', $invoice))->assertNotFound();
});

test('invitations create collaborators in the inviting business without Jetstream tables', function () {
    $a = newBusinessOwner('Alpha');
    $role = Role::where('name', 'Employee')->firstOrFail();
    $invite = app(BusinessContext::class)->run($a->team_id, fn () => UserInvitation::create([
        'email' => 'colleague@example.test', 'role_id' => $role->id, 'invited_by' => $a->id,
    ]));
    $this->post(route('laravel-crm.users.invitations.accept.store', $invite->code), [
        'name' => 'Colleague', 'password' => 'safe-test-password', 'password_confirmation' => 'safe-test-password',
    ])->assertRedirect();
    $created = User::withoutGlobalScopes()->where('email', 'colleague@example.test')->firstOrFail();
    expect($created->team_id)->toBe($a->team_id);
    expect(DB::table('model_has_roles')->where('model_id', $created->id)->value('team_id'))->toBe($a->team_id);
});

test('legacy adoption is explicit and preserves record UUIDs and fiscal data', function () {
    $owner = User::factory()->create(['crm_access' => true]);
    $record = app(BusinessContext::class)->system(fn () => Organization::create(['name' => 'Legacy customer']));
    app(BusinessContext::class)->system(fn () => Setting::create(['name' => 'vat_number', 'value' => 'LEGACY-TAX', 'global' => true]));
    $this->artisan('laravelcrm:adopt-business', ['owner' => $owner->id, '--name' => 'Legacy business', '--dry-run' => true])->assertSuccessful();
    expect(Business::count())->toBe(0);
    $this->artisan('laravelcrm:adopt-business', ['owner' => $owner->id, '--name' => 'Legacy business'])->assertSuccessful();
    $owner->refresh();
    expect($owner->team_id)->not->toBeNull()->and($owner->business->tax_id)->toBe('LEGACY-TAX');
    app(BusinessContext::class)->run($owner->team_id, fn () => expect(Organization::firstOrFail()->id)->toBe($record->id));
});

class BusinessProbeJob implements ShouldQueue
{
    public static array $observed = [];

    public function handle(): void
    {
        self::$observed[] = [app(BusinessContext::class)->id(), app('laravel-crm.settings')->get('vat_number'), Person::count()];
    }
}

test('queued work carries company context and restores it between jobs', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    BusinessProbeJob::$observed = [];
    $context = app(BusinessContext::class);
    $context->run($a->team_id, function () use ($a, $context) {
        Person::create(['first_name' => 'A']);
        Queue::connection('sync')->push(new BusinessProbeJob);
        expect($context->id())->toBe($a->team_id);
    });
    $context->run($b->team_id, fn () => Queue::connection('sync')->push(new BusinessProbeJob));
    expect(BusinessProbeJob::$observed)->toBe([
        [$a->team_id, 'RUC-Alpha', 1], [$b->team_id, 'RUC-Beta', 0],
    ])->and($context->id())->toBeNull();
});

test('the public business signup and CLI both provision an owner without null permission teams', function () {
    Livewire::test(Register::class)
        ->set('name', 'Web Owner')->set('email', 'web@example.test')
        ->set('password', 'safe-test-password')->set('password_confirmation', 'safe-test-password')
        ->set('business_name', 'Web Business')->call('register')
        ->assertHasNoErrors()->assertRedirect(route('laravel-crm.dashboard'));
    $web = User::withoutGlobalScopes()->where('email', 'web@example.test')->firstOrFail();
    expect($web->team_id)->not->toBeNull();
    Auth::logout();
    $this->artisan('laravelcrm:add-user', ['--business-name' => 'CLI Business'])
        ->expectsQuestion("Enter the user's email address", 'cli@example.test')
        ->expectsQuestion('First name', 'CLI')->expectsQuestion('Last name', 'Owner')
        ->expectsQuestion('Password', 'safe-test-password')->assertSuccessful();
    $cli = User::withoutGlobalScopes()->where('email', 'cli@example.test')->firstOrFail();
    expect($cli->team_id)->not->toBeNull()->not->toBe($web->team_id);
    expect(DB::table('model_has_roles')->whereNull('team_id')->count())->toBe(0);
});

test('campaign recipients and clicks remain isolated in background queries', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $context = app(BusinessContext::class);
    $recipient = $context->run($b->team_id, function () {
        $campaign = EmailCampaign::create(['name' => 'Private campaign', 'subject' => 'Secret', 'body' => 'Secret', 'status' => 'draft']);
        $person = Person::create(['first_name' => 'Recipient']);
        $email = $person->emails()->create(['address' => 'recipient@example.test']);
        $recipient = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id, 'email_id' => $email->id,
            'person_id' => $person->id, 'address' => 'recipient@example.test', 'status' => 'pending',
        ]);
        $recipient->clicks()->create(['original_url' => 'https://example.test', 'clicked_at' => now()]);

        return $recipient;
    });
    $context->run($a->team_id, function () use ($recipient) {
        expect(EmailCampaignRecipient::find($recipient->id))->toBeNull();
        expect(EmailCampaignRecipient::count())->toBe(0);
        expect(EmailCampaignClick::count())->toBe(0);
    });
    $context->run($b->team_id, fn () => expect(EmailCampaignRecipient::find($recipient->id))->not->toBeNull());
});

test('Sanctum issues a real UUID-user token and private API responses only contain that company data', function () {
    $a = newBusinessOwner('Alpha');
    $b = newBusinessOwner('Beta');
    $context = app(BusinessContext::class);
    $context->run($a->team_id, fn () => Person::create(['first_name' => 'Local']));
    $context->run($b->team_id, fn () => Person::create(['first_name' => 'Foreign']));
    $response = $this->postJson('/crm/api/v2/auth/token', [
        'email' => $a->email, 'password' => 'safe-test-password', 'device_name' => 'isolation-test',
    ])->assertCreated()->assertJsonPath('user.business.id', $a->team_id);
    $token = $response->json('token');
    $this->withToken($token)->getJson('/crm/api/v2/people')
        ->assertOk()->assertJsonCount(1, 'data')->assertSee('Local')->assertDontSee('Foreign');
});
