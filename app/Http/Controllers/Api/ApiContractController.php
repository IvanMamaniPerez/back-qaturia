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
            $snapshot = $contracts->snapshot($directory);
            $version = $snapshot['version'];
            $spec = $snapshot['spec'];
            $index = $snapshot['index'];
            if ($action === 'download') {
                return $this->download($request, $snapshot['files'], $version['contract_sha256']);
            }
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
                        'download' => route('api-contracts.query', ['action' => 'download']),
                    ],
                    'parameters' => [
                        'search' => ['q' => 'Search terms', 'limit' => '1-100, default 10'],
                        'operation' => ['operationId' => 'Identifier returned by search'],
                        'schema' => ['schemaName' => 'Schema name returned in components.schemas'],
                        'changes' => ['revision' => 'SHA-256 of an available historical contract'],
                        'download' => ['artifact' => ContractRepository::ARTIFACTS, 'revision' => 'Optional expected contract SHA-256', 'sha256' => 'Optional expected file SHA-256'],
                    ],
                    'artifacts' => $this->artifacts($snapshot['files'], $version['contract_sha256']),
                    'limits' => [
                        'default_bytes' => 131072, 'max_bytes' => config('api-contracts.http.max_bytes'),
                        'max_download_bytes' => config('api-contracts.http.max_download_bytes'),
                    ],
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
     * @param  array<string, string>  $files
     * @return array<string, array{url: string, sha256: string, bytes: int, media_type: string}>
     */
    private function artifacts(array $files, string $revision): array
    {
        $artifacts = [];
        foreach ($files as $name => $contents) {
            $checksum = hash('sha256', $contents);
            $artifacts[$name] = [
                'url' => route('api-contracts.query', ['action' => 'download', 'artifact' => $name, 'revision' => $revision, 'sha256' => $checksum]),
                'sha256' => $checksum, 'bytes' => strlen($contents), 'media_type' => 'application/json',
            ];
        }

        return $artifacts;
    }

    /** @param array<string, string> $files */
    private function download(QueryApiContractsRequest $request, array $files, string $revision): JsonResponse
    {
        $name = $request->validated('artifact');
        $contents = $files[$name];
        $checksum = hash('sha256', $contents);
        if (($request->validated('revision') !== null && $request->validated('revision') !== $revision)
            || ($request->validated('sha256') !== null && $request->validated('sha256') !== $checksum)) {
            return response()->json(['message' => 'The contract artifact changed; refresh discovery and retry the complete download.'], 409)
                ->header('Cache-Control', 'no-store, private');
        }
        $limit = config('api-contracts.http.max_download_bytes');
        if (strlen($contents) > $limit) {
            return response()->json(['message' => 'The artifact exceeds the download size limit.', 'required_bytes' => strlen($contents), 'max_download_bytes' => $limit], 413)
                ->header('Cache-Control', 'no-store, private');
        }

        return JsonResponse::fromJsonString($contents, 200, [
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'no-store, private',
            'Vary' => 'Cookie, Authorization',
            'X-Contract-Revision' => $revision,
            'X-Artifact-SHA256' => $checksum,
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
