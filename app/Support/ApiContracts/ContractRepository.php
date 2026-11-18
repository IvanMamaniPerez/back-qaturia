<?php

namespace App\Support\ApiContracts;

use Illuminate\Support\Facades\File;
use RuntimeException;
use stdClass;

class ContractRepository
{
    public const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

    public const ARTIFACTS = ['openapi.json', 'operations-index.json', 'contract-version.json', 'diagnostics.json'];

    /**
     * @return array<string, mixed>
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Contract file not found: {$path}");
        }

        return $this->decode(File::get($path));
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $json): array
    {
        $convert = function (mixed $value) use (&$convert): mixed {
            if ($value instanceof stdClass) {
                return count(get_object_vars($value)) ? array_map($convert, (array) $value) : $value;
            }

            return is_array($value) ? array_map($convert, $value) : $value;
        };
        $result = $convert(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
        if (! is_array($result)) {
            throw new RuntimeException('Expected a nonempty JSON object.');
        }

        return $result;
    }

    public function json(mixed $value): string
    {
        $sort = function (mixed $node) use (&$sort): mixed {
            if (! is_array($node)) {
                return $node;
            }
            if (! array_is_list($node)) {
                ksort($node);
            }

            return array_map($sort, $node);
        };

        return json_encode($sort($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    public function hash(mixed $value): string
    {
        return hash('sha256', $this->json($value));
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function validate(array $spec): void
    {
        if (! preg_match('/^3\.1\.\d+$/', $spec['openapi'] ?? '') || empty($spec['info']['version']) || empty($spec['paths'])) {
            throw new RuntimeException('Expected a nonempty OpenAPI 3.1 document.');
        }
        $ids = [];
        foreach ($spec['paths'] as $item) {
            foreach (['parameters' => $item['parameters'] ?? [], ...array_map(fn (string $method): array => $item[$method]['parameters'] ?? [], self::METHODS)] as $parameters) {
                $seen = [];
                foreach ($parameters as $parameter) {
                    $parameter = isset($parameter['$ref']) ? $this->resolve($spec, $parameter['$ref']) : $parameter;
                    $key = $parameter['in'].':'.$parameter['name'];
                    if (isset($seen[$key])) {
                        throw new RuntimeException("Duplicate parameter: {$key}");
                    }
                    $seen[$key] = true;
                }
            }
        }
        foreach ($this->operations($spec) as $id => $entry) {
            if (isset($ids[$id]) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9._-]+$/', $id)) {
                throw new RuntimeException("Invalid or duplicate operationId: {$id}");
            }
            $ids[$id] = true;
            if (empty($entry['operation']['responses'])) {
                throw new RuntimeException("Missing responses: {$id}");
            }
            foreach ($entry['operation']['security'] as $requirement) {
                foreach ((array) $requirement as $scheme => $scopes) {
                    if (! isset($spec['components']['securitySchemes'][$scheme])) {
                        throw new RuntimeException("Unknown security scheme: {$scheme}");
                    }
                }
            }
        }
        foreach ($this->refs($spec) as $ref) {
            $this->resolve($spec, $ref);
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, array{method: string, path: string, operation: array<string, mixed>}>
     */
    public function operations(array $spec): array
    {
        $operations = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach (self::METHODS as $method) {
                if (! isset($item[$method])) {
                    continue;
                }
                $operation = $item[$method];
                $id = $operation['operationId'] ?? '';
                if ($id === '' || isset($operations[$id])) {
                    throw new RuntimeException("Missing or duplicate operationId: {$id}");
                }
                $operation['security'] ??= $spec['security'] ?? [];
                $operation['servers'] ??= $item['servers'] ?? $spec['servers'] ?? [];
                $parameters = [];
                foreach (array_merge($item['parameters'] ?? [], $operation['parameters'] ?? []) as $parameter) {
                    $resolved = isset($parameter['$ref']) ? $this->resolve($spec, $parameter['$ref']) : $parameter;
                    $parameters[$resolved['in'].':'.$resolved['name']] = $parameter;
                }
                $operation['parameters'] = array_values($parameters);
                $operations[$id] = ['method' => strtoupper($method), 'path' => $path, 'operation' => $operation];
            }
        }
        ksort($operations);

        return $operations;
    }

    /** @return list<string> */
    private function refs(mixed $node): array
    {
        $refs = [];
        if (is_array($node)) {
            if (isset($node['$ref'])) {
                $refs[] = $node['$ref'];
            }
            foreach ($node as $value) {
                $refs = array_merge($refs, $this->refs($value));
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function resolve(array $spec, string $ref): mixed
    {
        if (! str_starts_with($ref, '#/')) {
            throw new RuntimeException("Only local JSON pointer references are allowed: {$ref}");
        }
        $value = $spec;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $key = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($part));
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                throw new RuntimeException("Unresolved reference: {$ref}");
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function bundle(array $spec, mixed $root): array
    {
        $document = ['openapi' => $spec['openapi'], 'info' => $spec['info'], 'contract' => $root];
        $pending = $this->refs($root);
        if (is_array($root) && isset($root['operation'])) {
            foreach ($root['operation']['security'] ?? [] as $requirement) {
                foreach ((array) $requirement as $name => $scopes) {
                    $pending[] = '#/components/securitySchemes/'.$name;
                }
            }
        }
        $seen = [];
        while ($pending !== []) {
            $ref = array_pop($pending);
            if (isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;
            $value = $this->resolve($spec, $ref);
            $target = &$document;
            foreach (explode('/', substr($ref, 2)) as $part) {
                $key = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($part));
                $target = &$target[$key];
            }
            $target = $value;
            unset($target);
            $pending = array_merge($pending, $this->refs($value));
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function operation(array $spec, string $id): array
    {
        $entry = $this->operations($spec)[$id] ?? throw new RuntimeException("Unknown operationId: {$id}");

        return $this->bundle($spec, $entry);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function schema(array $spec, string $name): array
    {
        $ref = '#/components/schemas/'.str_replace(['~', '/'], ['~0', '~1'], $name);
        $this->resolve($spec, $ref);

        return $this->bundle($spec, ['$ref' => $ref]);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function index(array $spec): array
    {
        $rows = [];
        foreach ($this->operations($spec) as $id => $entry) {
            $rows[] = [
                'operationId' => $id, 'method' => $entry['method'], 'path' => $entry['path'],
                'url_path' => ($entry['operation']['servers'][0]['url'] ?? '').$entry['path'],
                'tags' => $entry['operation']['tags'] ?? [], 'summary' => $entry['operation']['summary'] ?? '',
                'description' => $entry['operation']['description'] ?? '',
                'revision' => $this->hash($this->operation($spec, $id)),
                'status' => $entry['operation']['x-contract-status'] ?? 'inferred',
                'limitations' => $entry['operation']['x-contract-limitations'] ?? [],
            ];
        }

        return ['contract_sha256' => $this->hash($spec), 'operations' => $rows];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<array<string, mixed>>  $diagnostics
     * @return array<string, mixed>
     */
    public function withDiagnostics(array $spec, array $diagnostics): array
    {
        foreach ($spec['paths'] as $path => &$item) {
            foreach (self::METHODS as $method) {
                if (! isset($item[$method])) {
                    continue;
                }
                $bundle = $this->json($this->operation($spec, $item[$method]['operationId']));
                $matches = array_values(array_filter($diagnostics, function (array $diagnostic) use ($path, $method, $spec, $bundle): bool {
                    foreach ($diagnostic['details'] as [$label, $pointer]) {
                        if ($label !== 'Located at') {
                            continue;
                        }
                        if (str_starts_with($pointer, '/components/schemas/')) {
                            $name = explode('/', substr($pointer, strlen('/components/schemas/')))[0];
                            if (str_contains($bundle, '"'.$name.'":')) {
                                return true;
                            }
                        }
                        if (str_starts_with($pointer, '/paths/')) {
                            [$encodedPath, $verb] = explode('/', substr($pointer, strlen('/paths/')));
                            $decodedPath = '/'.str_replace(['~1', '~0'], ['/', '~'], $encodedPath);
                            if ($decodedPath === $spec['servers'][0]['url'].$path && $verb === $method) {
                                return true;
                            }
                        }
                    }

                    $contextMethod = strtolower($diagnostic['context']['method'] ?? '');

                    return ($diagnostic['context']['label'] ?? '') === $spec['servers'][0]['url'].$path
                        && ($contextMethod === $method || ($contextMethod === 'put' && $method === 'patch'));
                }));
                if ($matches !== []) {
                    $item[$method]['x-contract-status'] = 'partial';
                    $item[$method]['x-contract-diagnostics'] = $matches;
                    $item[$method]['x-contract-limitations'][] = 'Inference diagnostics are attached to this operation. Review before generating a production client.';
                }
            }
        }
        unset($item);

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $index
     * @return array<string, mixed>
     */
    public function search(array $index, string $query, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('limit must be between 1 and 100.');
        }
        $terms = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
        if ($terms === false) {
            throw new RuntimeException('Search query must contain valid UTF-8 text.');
        }
        $matches = array_values(array_filter($index['operations'], function (array $row) use ($terms): bool {
            $haystack = mb_strtolower($this->json($row));
            foreach ($terms as $term) {
                if (! str_contains($haystack, $term)) {
                    return false;
                }
            }

            return true;
        }));

        return ['contract_sha256' => $index['contract_sha256'], 'total' => count($matches), 'operations' => array_slice($matches, 0, $limit)];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    public function changes(array $before, array $after): array
    {
        $compare = function (array $old, array $new): array {
            return [
                'added' => array_values(array_diff(array_keys($new), array_keys($old))),
                'removed' => array_values(array_diff(array_keys($old), array_keys($new))),
                'modified' => array_values(array_filter(array_intersect(array_keys($old), array_keys($new)), fn (string $key): bool => $old[$key] !== $new[$key])),
            ];
        };
        $hashOperations = fn (array $spec): array => array_column($this->index($spec)['operations'], 'revision', 'operationId');
        $hashSchemas = fn (array $spec): array => array_map(fn (mixed $schema): string => $this->hash($schema), $spec['components']['schemas'] ?? []);
        $operations = $compare($hashOperations($before), $hashOperations($after));
        $schemas = $compare($hashSchemas($before), $hashSchemas($after));

        return [
            'from' => $this->hash($before), 'to' => $this->hash($after),
            'operations' => $operations, 'schemas' => $schemas,
            'requires_review' => $operations['removed'] !== [] || $operations['modified'] !== [] || $schemas['removed'] !== [] || $schemas['modified'] !== [],
            'policy' => 'Conservative: all modified or removed contracts require compatibility review, including transitive dependencies.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $directory): array
    {
        return $this->snapshot($directory)['version'];
    }

    /**
     * @return array{version: array<string, mixed>, spec: array<string, mixed>, index: array<string, mixed>, files: array<string, string>}
     */
    public function snapshot(string $directory): array
    {
        $files = [];
        $documents = [];
        foreach (self::ARTIFACTS as $name) {
            $path = $directory.'/'.$name;
            if (! is_file($path)) {
                throw new RuntimeException("Contract file not found: {$path}");
            }
            $files[$name] = File::get($path);
            $documents[$name] = $this->decode($files[$name]);
        }
        $spec = $documents['openapi.json'];
        $this->validate($spec);
        $version = $documents['contract-version.json'];
        $index = $documents['operations-index.json'];
        if ($version['contract_sha256'] !== $this->hash($spec)
            || hash('sha256', $files['openapi.json']) !== $version['contract_sha256']
            || $this->json($index) !== $this->json($this->index($spec))) {
            throw new RuntimeException('Specification, index and version belong to different revisions.');
        }
        $diagnostics = $documents['diagnostics.json'];
        if ($diagnostics['contract_sha256'] !== $version['contract_sha256'] || $this->hash($diagnostics['diagnostics']) !== $version['diagnostics_sha256']) {
            throw new RuntimeException('Diagnostics belong to a different revision.');
        }

        return ['version' => $version, 'spec' => $spec, 'index' => $index, 'files' => $files];
    }

    public function sourceHash(): string
    {
        $files = [base_path('composer.json'), base_path('composer.lock')];
        foreach (['app', 'bootstrap', 'config', 'database/migrations', 'routes', 'modules/crm/src', 'modules/crm/database'] as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                if ($directory === 'bootstrap' && str_contains($file->getPathname(), '/cache/')) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        $context = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($context, str_replace(base_path().'/', '', $file)."\0".File::get($file)."\0");
        }

        return hash_final($context);
    }
}
