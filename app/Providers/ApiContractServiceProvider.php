<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ApiContractServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        if (class_exists(Scramble::class)) {
            Scramble::ignoreDefaultRoutes();
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::configure()
            ->routes(fn (Route $route): bool => in_array($route->getName(), config('api-contracts.routes'), true))
            ->resolveOperationMethodsUsing(fn (Route $route): array => array_values(array_diff($route->methods(), ['HEAD'])))
            ->withOperationTransformers(function (Operation $operation, RouteInfo $info): void {
                $name = Str::after($info->route->getName(), 'laravel-crm.api.v2.');
                $operation->operationId = 'crm.v2.'.$name.'.'.strtolower($operation->method);
                $operation->summary = Str::headline(str_replace('.', ' ', $name));
                $operation->tags = [Str::before($name, '.')];
                $operation->addParameters([Parameter::make('Accept', 'header')->setSchema(Schema::fromType((new StringType)->enum(['application/json'])))]);
                $middleware = $info->route->gatherMiddleware();
                if (in_array('auth:sanctum', $middleware, true)) {
                    $operation->security = [new SecurityRequirement(['http' => []]), new SecurityRequirement(['frontendSession' => []])];
                }
                if (in_array('laravel-crm.api.team', $middleware, true)) {
                    $operation->addParameters([Parameter::make('X-Team-ID', 'header')->setSchema(Schema::fromType((new StringType)->format('uuid')))->description('Optional assertion of the authenticated user team. A different team returns 403 when teams are enabled.')]);
                    $this->addError($operation, 403, 'CRM access, team context or policy permission denied.');
                }
                if (str_contains($info->route->uri(), '{')) {
                    $this->addError($operation, 404, 'Resource not found within the current business or parent.');
                }
                if (Str::endsWith($name, '.index') && ! str_contains($name, 'options')) {
                    $operation->addParameters([
                        Parameter::make('page', 'query')->setSchema(Schema::fromType(new IntegerType)),
                        Parameter::make('per_page', 'query')->setSchema(Schema::fromType(new IntegerType))->description('Default 25, capped at 100; values below 1 use the default.'),
                        ...$this->listFilters($name),
                    ]);
                }
                if ($name === 'auth.login') {
                    $this->addError($operation, 419, 'A stateful frontend origin and CSRF cookie are required.');
                }
                if ($name === 'auth.register') {
                    $this->addError($operation, 403, 'Registration or business mode is disabled.');
                }
                if (str_contains($name, 'products.') && in_array($info->methodName(), ['apply', 'bulk', 'archive', 'restore', 'update'], true)) {
                    $this->addError($operation, 409, 'Catalog version, plan or idempotency conflict.');
                }
                if (str_contains($info->className() ?? '', 'ProductOptionController')) {
                    $operation->setExtensionProperty('contract-status', 'partial');
                    $operation->setExtensionProperty('contract-limitations', ['Dynamic matrix command and response inference requires review; see docs/API_CONTRACTS.md and diagnostics.json.']);
                }
                $parameters = [];
                foreach ($operation->parameters as $parameter) {
                    if ($parameter instanceof Parameter) {
                        $parameters[$parameter->in.':'.$parameter->name] = $parameter;
                    } else {
                        $parameters[] = $parameter;
                    }
                }
                $operation->parameters = array_values($parameters);
            })
            ->withDocumentTransformers(function (OpenApi $document): void {
                $document->components->addSecurityScheme('frontendSession', SecurityScheme::apiKey('cookie', config('session.cookie'))->setDescription('Sanctum stateful SPA session. First GET /sanctum/csrf-cookie; send credentials and X-XSRF-TOKEN for unsafe requests from a configured stateful origin.'));
            });

        if ($this->app->environment('local')) {
            Scramble::registerUiRoute('docs/api');
            Scramble::registerJsonSpecificationRoute('docs/api.json');
        }
    }

    /** @return list<Parameter> */
    private function listFilters(string $name): array
    {
        if ($name === 'products.variations.index') {
            return [Parameter::make('currency', 'query')->setSchema(Schema::fromType(new StringType))->description('Currency for effective_price; defaults to CRM settings or USD.')];
        }
        $sorts = match (Str::before($name, '.')) {
            'products' => ['created_at', 'updated_at', 'name', 'code'],
            'people' => ['created_at', 'updated_at', 'first_name', 'last_name'],
            'organizations' => ['created_at', 'updated_at', 'name'],
            'leads', 'deals' => ['created_at', 'updated_at', 'title', 'amount'],
            'quotes' => ['created_at', 'updated_at', 'title', 'total'],
            default => ['created_at', 'updated_at', 'total'],
        };
        $parameters = [
            Parameter::make('sort', 'query')->setSchema(Schema::fromType((new StringType)->enum([...$sorts, ...array_map(fn (string $field): string => '-'.$field, $sorts)])))->description('Default -created_at. Unsupported values are ignored.'),
            Parameter::make('user_owner_id', 'query')->setSchema(Schema::fromType((new StringType)->format('uuid'))),
        ];
        if ($name === 'products.index') {
            $parameters[] = Parameter::make('active', 'query')->setSchema(Schema::fromType(new StringType))->description('Parsed with FILTER_VALIDATE_BOOLEAN.');
        }

        return $parameters;
    }

    private function addError(Operation $operation, int $status, string $description): void
    {
        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && (int) $response->code === $status) {
                return;
            }
        }
        $operation->addResponse(Response::make($status)->setDescription($description));
    }
}
