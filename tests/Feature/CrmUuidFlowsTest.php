<?php

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use VentureDrake\LaravelCrm\Http\Requests\Api\V2\StoreInvoiceRequest;
use VentureDrake\LaravelCrm\Livewire\EmailTemplates\EmailTemplateCreate;
use VentureDrake\LaravelCrm\Livewire\People\PersonImport;
use VentureDrake\LaravelCrm\Livewire\SmsTemplates\SmsTemplateCreate;
use VentureDrake\LaravelCrm\Livewire\Traits\HasVariationOptions;
use VentureDrake\LaravelCrm\Livewire\Users\UserShow;
use VentureDrake\LaravelCrm\Models\ChatConversation;
use VentureDrake\LaravelCrm\Models\ChatMessage;
use VentureDrake\LaravelCrm\Models\ChatWidget;
use VentureDrake\LaravelCrm\Models\Customer;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\EmailTemplate;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductVariation;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\SmsTemplate;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrm\Models\UsageRequest;
use VentureDrake\LaravelCrm\Services\ChatService;
use VentureDrake\LaravelCrm\Support\LegacyUuid;
use VentureDrake\LaravelCrm\Support\PortalTeam;
use VentureDrake\LaravelCrm\Traits\ResolvesProductVariation;

test('commercial records retain UUID foreign keys and polymorphic relationships', function () {
    $organization = Organization::create(['name' => 'Commercial chain']);
    $person = Person::create(['first_name' => 'Contact', 'organization_id' => $organization->id]);
    $customer = Customer::create([
        'customerable_type' => $person->getMorphClass(), 'customerable_id' => $person->id,
    ]);
    $lead = Lead::create(['title' => 'Lead', 'person_id' => $person->id, 'organization_id' => $organization->id]);
    $deal = Deal::create(['title' => 'Deal', 'lead_id' => $lead->id, 'person_id' => $person->id]);
    $quote = Quote::create(['title' => 'Quote', 'deal_id' => $deal->id, 'person_id' => $person->id]);
    $order = Order::create(['quote_id' => $quote->id]);
    $invoice = Invoice::create(['order_id' => $order->id]);
    $delivery = Delivery::create(['order_id' => $order->id]);
    $product = Product::create(['name' => 'Product']);
    $variation = ProductVariation::create(['name' => 'Variation', 'product_id' => $product->id, 'active' => true]);
    $line = $order->orderProducts()->create(['product_id' => $product->id, 'product_variation_id' => $variation->id]);
    $deliveryLine = $delivery->deliveryProducts()->create(['order_product_id' => $line->id]);
    $task = Task::create([
        'name' => 'Follow up', 'taskable_type' => $deal->getMorphClass(), 'taskable_id' => $deal->id,
    ]);

    foreach ([$organization, $person, $customer, $lead, $deal, $quote, $order, $invoice, $delivery, $product, $variation, $line, $deliveryLine, $task] as $model) {
        expect(Str::isUuid($model->id))->toBeTrue();
        $this->assertModelExists($model);
    }
    expect($person->fresh()->organization->id)->toBe($organization->id)
        ->and($customer->fresh()->customerable->id)->toBe($person->id)
        ->and($deal->fresh()->lead->id)->toBe($lead->id)
        ->and($quote->fresh()->deal->id)->toBe($deal->id)
        ->and($order->fresh()->quote->id)->toBe($quote->id)
        ->and($invoice->fresh()->order->id)->toBe($order->id)
        ->and($deliveryLine->fresh()->orderProduct->productVariation->id)->toBe($variation->id)
        ->and($task->fresh()->taskable->id)->toBe($deal->id);

    $line->update(['quantity' => 2]);
    expect($line->fresh()->product_id)->toBe($product->id);
    $task->delete();
    $this->assertSoftDeleted($task);
});

test('all CRM models declare string nonincrementing UUID primary keys', function () {
    foreach (glob(base_path('modules/crm/src/Models/*.php')) as $file) {
        $class = 'VentureDrake\\LaravelCrm\\Models\\'.basename($file, '.php');
        $model = new $class;

        expect($model->getKeyName())->toBe('id')
            ->and($model->getKeyType())->toBe('string')
            ->and($model->getIncrementing())->toBeFalse();
    }
});

test('usage requests and deliveries persist generated UUID keys', function () {
    $usage = UsageRequest::create([
        'host' => 'localhost', 'path' => 'crm/dashboard', 'url' => 'http://localhost/crm/dashboard',
        'method' => 'GET', 'route' => 'laravel-crm.dashboard',
    ]);
    $delivery = Delivery::create([]);

    expect(Str::isUuid($usage->id))->toBeTrue()
        ->and(Str::isUuid($delivery->id))->toBeTrue()
        ->and(UsageRequest::findOrFail($usage->id)->id)->toBe($usage->id)
        ->and(Delivery::findOrFail($delivery->id)->id)->toBe($delivery->id);

    $usage->delete();
    $this->assertSoftDeleted($usage);
    $usage->restore();
    expect(UsageRequest::findOrFail($usage->id)->id)->toBe($usage->id);
});

test('API owner validation accepts UUIDs and rejects legacy integers', function (string $requestName) {
    $owner = User::factory()->create();
    $owner->forceFill(['crm_access' => true])->save();
    $class = 'VentureDrake\\LaravelCrm\\Http\\Requests\\Api\\V2\\'.$requestName;
    $rules = (new $class)->rules();

    $valid = validator(['user_owner_id' => $owner->id], ['user_owner_id' => $rules['user_owner_id']]);
    $invalid = validator(['user_owner_id' => 123], ['user_owner_id' => $rules['user_owner_id']]);

    expect($valid->passes())->toBeTrue()
        ->and($invalid->fails())->toBeTrue();
})->with([
    'StoreDealRequest', 'UpdateDealRequest', 'StoreLeadRequest', 'UpdateLeadRequest',
    'StoreQuoteRequest', 'UpdateQuoteRequest', 'StoreOrderRequest', 'UpdateOrderRequest',
    'StoreInvoiceRequest', 'UpdateInvoiceRequest', 'StoreProductRequest', 'UpdateProductRequest',
    'StoreOrganizationRequest', 'UpdateOrganizationRequest', 'StorePersonRequest', 'UpdatePersonRequest',
]);

test('organization API stores UUID owners and rejects numeric owners with 422', function () {
    $owner = User::factory()->create();
    $owner->forceFill(['crm_access' => true])->save();
    $owner->givePermissionTo(Permission::create(['name' => 'create crm organizations', 'guard_name' => 'web']));
    $this->actingAs($owner);

    $this->postJson(route('laravel-crm.api.v2.organizations.store'), [
        'name' => 'UUID organization', 'user_owner_id' => $owner->id,
    ])->assertCreated();

    $this->assertDatabaseHas('crm_organizations', ['name' => 'UUID organization', 'user_owner_id' => $owner->id]);
    $this->postJson(route('laravel-crm.api.v2.organizations.store'), [
        'name' => 'Invalid owner', 'user_owner_id' => 123,
    ])->assertUnprocessable()->assertJsonValidationErrors('user_owner_id');
    $this->assertDatabaseMissing('crm_organizations', ['name' => 'Invalid owner']);
});

test('variation validation rejects a different product with the same numeric UUID prefix', function () {
    $product = Product::forceCreate(['id' => '019ab001-1234-7000-8000-000000000001', 'name' => 'First']);
    $other = Product::forceCreate(['id' => '019ab001-1234-7000-8000-000000000002', 'name' => 'Other']);
    $variation = ProductVariation::create(['product_id' => $other->id, 'name' => 'Other variation', 'active' => true]);
    $request = new StoreInvoiceRequest;
    $request->replace(['line_items' => [['product_id' => $product->external_id, 'product_variation_id' => $variation->external_id]]]);
    $validator = validator($request->all(), []);
    foreach ($request->after() as $callback) {
        $validator->after($callback);
    }

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('line_items.0.product_variation_id'))->toBeTrue();
});

test('line item variation helpers preserve UUIDs and restrict deleted variations to the current line', function () {
    $product = Product::create(['name' => 'Product']);
    $active = ProductVariation::forceCreate([
        'id' => '019ab001-1234-7000-8000-000000000001',
        'product_id' => $product->id, 'name' => 'Active', 'active' => true,
    ]);
    $deleted = ProductVariation::forceCreate([
        'id' => '019ab001-1234-7000-8000-000000000002',
        'product_id' => $product->id, 'name' => 'Deleted', 'active' => true,
    ]);
    $deleted->delete();
    $helper = new class
    {
        use HasVariationOptions;
        use ResolvesProductVariation { variationIdFor as public;
            resolveRowVariation as public; }
    };

    expect(array_column($helper->variationOptions($product->id), 'id'))->toBe([$active->id])
        ->and(array_column($helper->variationOptions($product->id, $deleted->id), 'id'))->toBe([$active->id, $deleted->id])
        ->and($helper->resolveRowVariation($product->id, $active->id)?->id)->toBe($active->id)
        ->and($helper->variationIdFor($product->id, $active->id))->toBe($active->id)
        ->and($helper->variationIdFor($product->id, $deleted->id, $deleted->id))->toBe($deleted->id)
        ->and($helper->variationOptions('not-a-uuid'))->toBe([]);
    expect(fn () => $helper->variationIdFor($product->id, $deleted->id, $active->id))
        ->toThrow(ValidationException::class, 'INVALID_PRODUCT_VARIATION');
});

test('billing and shipping addresses resolve their deterministic UUID catalog keys', function () {
    $organization = Organization::create(['name' => 'Addresses']);
    $billing = $organization->addresses()->create(['external_id' => (string) Str::uuid(), 'address_type_id' => LegacyUuid::fromInteger(5), 'line1' => 'Billing']);
    $shipping = $organization->addresses()->create(['external_id' => (string) Str::uuid(), 'address_type_id' => LegacyUuid::fromInteger(6), 'line1' => 'Shipping']);

    expect($organization->getBillingAddress()?->id)->toBe($billing->id)
        ->and($organization->getShippingAddress()?->id)->toBe($shipping->id);
});

test('portal team resolution keeps the complete UUID in sessions and documents', function () {
    config(['laravel-crm.teams' => true]);
    $teamId = '019ab001-1234-7000-8000-000000000001';

    expect(PortalTeam::resolve($teamId))->toBe($teamId)
        ->and(PortalTeam::remembered())->toBe($teamId)
        ->and(PortalTeam::resolve())->toBe($teamId)
        ->and(PortalTeam::forDocument((object) ['team_id' => $teamId]))->toBe($teamId);
});

test('CRM template cloning preserves UUID source identifiers', function (string $modelClass, string $componentClass, array $attributes) {
    $template = $modelClass::create(['name' => 'Source', 'body' => 'Body', ...$attributes]);
    app('request')->query->set('clone_from', $template->id);
    $component = new $componentClass;

    $component->mount();

    expect($component->clone_from)->toBe($template->id)
        ->and($component->name)->toBe('Source (copy)');
})->with([
    [EmailTemplate::class, EmailTemplateCreate::class, ['subject' => 'Subject']],
    [SmsTemplate::class, SmsTemplateCreate::class, []],
]);

test('person imports retain UUID organization relationships', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::create(['name' => 'create crm people', 'guard_name' => 'web']));
    $organization = Organization::create(['name' => 'Import organization']);
    $this->actingAs($user);
    session(['crm_person_import_preview' => [[
        'row' => 2, 'title' => '', 'first_name' => 'Imported', 'last_name' => 'Person', 'description' => '',
        'organization_name' => $organization->name, 'email' => '', 'phone' => '',
    ]]]);

    Livewire\Livewire::test(PersonImport::class)
        ->call('startImport')->call('processNextChunk');

    $this->assertDatabaseHas('crm_people', ['first_name' => 'Imported', 'organization_id' => $organization->id]);
});

test('CRM user deletion distinguishes UUIDs with the same numeric prefix', function () {
    $owner = User::factory()->create(['id' => '019ab001-1234-7000-8000-000000000001']);
    $target = User::factory()->create(['id' => '019ab001-1234-7000-8000-000000000002']);
    $owner->givePermissionTo(Permission::create(['name' => 'delete crm users', 'guard_name' => 'web']));
    $this->actingAs($owner);

    $component = Livewire\Livewire::test(UserShow::class, ['user' => $target]);
    $component->call('delete', $owner->id);
    $this->assertModelExists($owner);
    $component->call('delete', $target->id);

    $this->assertDatabaseMissing('users', ['id' => $target->id]);
    $this->assertModelExists($owner);
});

function uuidChatFixture(): ChatConversation
{
    $widget = ChatWidget::create(['name' => 'UUID chat']);
    $visitor = app(ChatService::class)->findOrCreateVisitor($widget, null);

    return app(ChatService::class)->openConversationForVisitor($visitor);
}

test('chat references continue from existing references and latest messages support UUIDs', function () {
    $first = uuidChatFixture();
    $first->update(['chat_id' => 'C1100']);
    Cache::flush();
    $second = uuidChatFixture();
    $old = $second->messages()->create(['body' => 'Old', 'sender_type' => 'system', 'created_at' => now()->subMinute()]);
    $latest = $second->messages()->create(['body' => 'Latest', 'sender_type' => 'system']);

    expect($first->chat_id)->toBe('C1100')
        ->and($second->chat_id)->toBe('C1101')
        ->and($second->latestMessage?->id)->toBe($latest->id)
        ->and(ChatConversation::with('latestMessage')->findOrFail($second->id)->latestMessage?->id)->toBe($latest->id)
        ->and(Str::isUuid($old->id))->toBeTrue();
});

test('chat polling accepts UUID cursors and respects timestamp order', function () {
    $chat = uuidChatFixture();
    $first = ChatMessage::forceCreate([
        'id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'chat_conversation_id' => $chat->id,
        'body' => 'First', 'sender_type' => 'system', 'created_at' => now()->subMinute(),
    ]);
    $next = ChatMessage::forceCreate([
        'id' => '00000000-0000-4000-8000-000000000001', 'chat_conversation_id' => $chat->id,
        'body' => 'Next', 'sender_type' => 'system',
    ]);
    $url = route('laravel-crm.portal.chat.messages', ['publicKey' => $chat->widget->public_key]);
    $token = $chat->visitor->visitor_token;

    $this->getJson($url.'?'.http_build_query(['token' => $token, 'since_id' => $first->id]))
        ->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.id', $next->id);
    $this->getJson($url.'?'.http_build_query(['token' => $token, 'since_id' => 'invalid']))
        ->assertUnprocessable()->assertJsonValidationErrors('since_id');
});
