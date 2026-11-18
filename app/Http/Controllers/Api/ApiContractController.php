<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QueryApiContractsRequest;
use App\Support\ApiContracts\ContractRepository;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiContractController extends Controller
{
    public function __invoke(QueryApiContractsRequest $request, ContractRepository $contracts): JsonResponse
    {
        $directory = config('api-contracts.directory');
        $action = $request->validated('action') ?? ($request->has('q') ? 'search' : 'discovery');
        try {
            $version = $contracts->verify($directory);
            $spec = $contracts->read($directory.'/openapi.json');
            $index = $contracts->read($directory.'/operations-index.json');
            $result = match ($action) {
                'search' => $contracts->search($index, $request->validated('q', ''), (int) $request->validated('limit', 10)),
                'operation' => $this->operation($contracts, $spec, $request->validated('operationId')),
                'schema' => $this->schema($contracts, $spec, $request->validated('schemaName')),
                'changes' => $this->changes($contracts, $spec, $directory, $request->validated('revision')),
                default => [
                    'operation_count' => count($index['operations']),
                    'schema_count' => count($spec['components']['schemas'] ?? []),
                    'links' => [
                        'self' => route('api-contracts.discovery'),
                        'query' => route('api-contracts.query'),
                        'search' => route('api-contracts.query', ['action' => 'search']),
                        'operation' => route('api-contracts.query', ['action' => 'operation']),
                        'schema' => route('api-contracts.query', ['action' => 'schema']),
                        'changes' => route('api-contracts.query', ['action' => 'changes']),
                    ],
                    'parameters' => [
                        'search' => ['q' => 'Search terms', 'limit' => '1-100, default 10'],
                        'operation' => ['operationId' => 'Identifier returned by search'],
                        'schema' => ['schemaName' => 'Schema name returned in components.schemas'],
                        'changes' => ['revision' => 'SHA-256 of an available historical contract'],
                    ],
                    'limits' => ['default_bytes' => 131072, 'max_bytes' => config('api-contracts.http.max_bytes')],
                ],
            };
            $json = $contracts->json(['contract_sha256' => $version['contract_sha256'], 'status' => $version['status'], ...$result]);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Contract artifacts are unavailable or inconsistent.'], 503)->header('Cache-Control', 'no-store, private');
        }
        $limit = (int) $request->validated('max_bytes', 131072);
        if (strlen($json) > $limit) {
            return response()->json([
                'message' => 'The complete result exceeds max_bytes; no contract data was truncated.',
                'required_bytes' => strlen($json), 'max_bytes' => $limit,
            ], 413)->header('Cache-Control', 'no-store, private');
        }

        return JsonResponse::fromJsonString($json, 200, [
            'Cache-Control' => 'no-store, private',
            'Vary' => 'Cookie, Authorization',
            'X-Contract-Revision' => $version['contract_sha256'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function operation(ContractRepository $contracts, array $spec, string $id): array
    {
        abort_unless(isset($contracts->operations($spec)[$id]), 404, 'Unknown operationId.');

        return $contracts->operation($spec, $id);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function schema(ContractRepository $contracts, array $spec, string $name): array
    {
        abort_unless(isset($spec['components']['schemas'][$name]), 404, 'Unknown schemaName.');

        return $contracts->schema($spec, $name);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function changes(ContractRepository $contracts, array $spec, string $directory, string $revision): array
    {
        $previous = $spec;
        if ($contracts->hash($spec) !== $revision) {
            $path = $directory.'/revisions/'.$revision.'/openapi.json';
            abort_unless(is_file($path), 404, 'Unknown contract revision.');
            $previous = $contracts->read($path);
            if ($contracts->hash($previous) !== $revision) {
                throw new RuntimeException('Historical snapshot hash mismatch.');
            }
        }

        return $contracts->changes($previous, $spec);
    }
}
