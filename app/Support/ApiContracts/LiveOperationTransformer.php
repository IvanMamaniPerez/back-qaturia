<?php

namespace App\Support\ApiContracts;

use App\Services\LiveEventSchema;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\RulesToParameters;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

class LiveOperationTransformer implements OperationTransformer
{
    public function __construct(private TypeTransformer $types, private LiveEventSchema $events) {}

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $name = $routeInfo->route->getName() ?? '';
        if (! str_starts_with($name, 'live.v1.')) {
            return;
        }
        $operation->operationId = $name.'.'.strtolower($operation->method);
        $operation->tags = ['LIVE'];
        $operation->servers([Server::make('/')]);
        $operation->addParameters([
            Parameter::make('Accept', 'header')->setSchema(Schema::fromType((new StringType)->enum(['application/json']))),
            Parameter::make('X-Team-ID', 'header')->setSchema(Schema::fromType((new StringType)->format('uuid')))->description('Optional assertion only; must match the authenticated business.'),
        ]);
        $operation->security = [new SecurityRequirement(['http' => []]), new SecurityRequirement(['frontendSession' => []])];
        foreach ([401 => 'Missing, expired or revoked authentication.', 403 => 'LIVE permission or business assertion denied.', 413 => 'Payload byte limit exceeded.', 429 => 'LIVE request rate exceeded.'] as $code => $description) {
            $this->error($operation, $code, $description);
        }
        if (str_contains($name, '.store')) {
            $this->error($operation, 409, 'Session or connector identity conflicts with an existing authorization.');
        }
        $components = $this->types->getComponents();
        $variants = [];
        $payloads = [];
        foreach (array_keys(LiveEventSchema::SOURCES) as $type) {
            $schema = $this->schemaFromRules($this->events->rules($type));
            if ($schema->type instanceof ObjectType) {
                $schema->type->properties['schemaVersion']?->enum([1]);
            }
            $schemaName = 'Live'.Str::studly(str_replace('.', '_', Str::after($type, 'live.'))).'Envelope';
            $variants[] = $components->addSchema($schemaName, $schema);
            if ($schema->type instanceof ObjectType) {
                $payloads[] = $schema->type->properties['payload'];
            }
        }
        foreach ($components->schemas as $schemaName => $schema) {
            if (str_ends_with($schemaName, 'LiveEventResource') && $schema instanceof Schema && $schema->type instanceof ObjectType) {
                $schema->type->properties['payload'] = (new AnyOf)->setItems($payloads);
            }
        }
        foreach ($components->schemas as $schemaName => $schema) {
            if (str_contains($schemaName, 'Live') && str_ends_with($schemaName, 'Request') && $schema instanceof Schema && $schema->type instanceof ObjectType) {
                unset($schema->type->properties['businessId'], $schema->type->properties['team_id']);
            }
        }
        if ($name !== 'live.v1.events.ingest') {
            return;
        }
        $requestBody = $operation->requestBodyObject;
        $body = $requestBody instanceof RequestBodyObject ? ($requestBody->content['application/json'] ?? null) : null;
        $body = $body instanceof Reference ? $body->resolve() : $body;
        if ($body instanceof Schema && $body->type instanceof ObjectType) {
            $body->type->properties['contractVersion']?->enum([1]);
            $body->type->properties['events'] = (new ArrayType)->setItems((new AnyOf)->setItems($variants))->setMin(1)->setMax(100);
        }
        $operation->description .= ' Each event has an independent result. The entire persistence transaction commits before acknowledgment; a 503 acknowledges none and must be retried unchanged. Captions and viewer-picks diagnostics are disabled by default. Source timestamps retain supplied provenance; local observation times are not official TikTok times. Events without stable source IDs only deduplicate transport retries. See docs/LIVE_API.md.';
        $status = (new ObjectType)->addProperty('eventId', (new StringType)->nullable(true))
            ->addProperty('status', (new StringType)->enum(['accepted', 'duplicate', 'rejected']))
            ->addProperty('code', (new StringType)->nullable(true)->enum(['INVALID_EVENT', 'EVENT_TOO_LARGE', 'UNSUPPORTED_SCHEMA_VERSION', 'UNSUPPORTED_EVENT_TYPE', 'FEATURE_DISABLED', 'SESSION_MISMATCH', 'CONNECTOR_NOT_AUTHORIZED', 'IDEMPOTENCY_CONFLICT']))
            ->addProperty('canonicalEventId', (new StringType)->format('uuid')->nullable(true))
            ->addProperty('errors', (new ObjectType)->additionalProperties((new ArrayType)->setItems(new StringType)))
            ->setRequired(['eventId', 'status', 'code', 'canonicalEventId', 'errors']);
        $response = (new ObjectType)->addProperty('batchId', (new StringType)->format('uuid'))
            ->addProperty('results', (new ArrayType)->setItems($status))->setRequired(['batchId', 'results']);
        $operation->responses = array_values(array_filter($operation->responses ?? [], fn ($item): bool => ! ($item instanceof Response) || (int) $item->code !== 200));
        $operation->addResponse(Response::make(200)->setContent('application/json', Schema::fromType($response)));
        $this->error($operation, 503, 'PERSISTENCE_UNAVAILABLE: no event in this transaction was acknowledged. Retry identical identities.');
    }

    /** @param array<string, list<string>> $rules */
    private function schemaFromRules(array $rules): Schema
    {
        $parameters = (new RulesToParameters($rules, [], $this->types, 'body'))->handle();
        $schema = Schema::createFromParameters($parameters);

        return Schema::fromType($this->closeObjects($schema->type));
    }

    private function closeObjects(Type $type): Type
    {
        if ($type instanceof ObjectType) {
            $closed = new LiveObjectType;
            $closed->required = $type->required;
            foreach ($type->properties as $field => $property) {
                $closed->properties[$field] = $property ? $this->closeObjects($property) : null;
            }

            return $closed;
        }
        if ($type instanceof ArrayType) {
            $type->items = $this->closeObjects($type->items);
        }

        return $type;
    }

    private function error(Operation $operation, int $status, string $description): void
    {
        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && (int) $response->code === $status) {
                return;
            }
        }
        $operation->addResponse(Response::make($status)->setDescription($description));
    }
}
