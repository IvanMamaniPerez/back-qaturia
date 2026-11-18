<?php

namespace App\Console\Commands;

use App\Support\ApiContracts\ContractRepository;
use Composer\InstalledVersions;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

class ApiContractsCommand extends Command
{
    protected $signature = 'api:contracts
        {action=search : generate, check, search, operation, schema, changes, sync}
        {value? : Search text, operationId, schema name, or previous SHA-256}
        {--directory=contracts : Contract directory, including an external checkout}
        {--limit=10 : Search result limit (1-100)}
        {--max-bytes=131072 : Maximum stdout bytes; never truncates contracts}
        {--output= : Write the complete result to a file instead of stdout}
        {--allow-partial : Explicitly allow a draft with documented inference limitations}
        {--against= : Previous OpenAPI JSON file for compatibility comparison}
        {--fail-on-breaking : Fail on any modified or removed existing contract}
        {--destination= : New directory for a verified draft copy}';

    protected $description = 'Generate, verify and selectively retrieve versioned OpenAPI contracts';

    public function handle(ContractRepository $contracts): int
    {
        try {
            $directory = $this->directory((string) $this->option('directory'));
            $action = $this->argument('action');
            $value = (string) ($this->argument('value') ?? '');
            if ($action === 'generate') {
                return $this->generate($contracts, $directory);
            }
            $version = $contracts->verify($directory);
            if ($action === 'check') {
                if ($version['source_sha256'] !== $contracts->sourceHash()) {
                    throw new RuntimeException('Contracts are stale for this source tree. Run api:contracts generate.');
                }
                $this->emit($contracts, $version);

                return self::SUCCESS;
            }
            if ($action === 'search') {
                $this->emit($contracts, $contracts->search($contracts->read($directory.'/operations-index.json'), $value, (int) $this->option('limit')));

                return self::SUCCESS;
            }
            $spec = $contracts->read($directory.'/openapi.json');
            $result = match ($action) {
                'operation' => $contracts->operation($spec, $value),
                'schema' => $contracts->schema($spec, $value),
                'changes' => $contracts->changes($this->previous($contracts, $directory, $value), $spec),
                'sync' => $this->sync($contracts, $directory, $version),
                default => throw new RuntimeException("Unknown action: {$action}"),
            };
            $this->emit($contracts, ['contract_sha256' => $version['contract_sha256'], ...$result]);

            return $action === 'changes' && $this->option('fail-on-breaking') && $result['requires_review'] ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->getOutput()->getErrorStyle()->writeln($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function directory(string $path): string
    {
        return str_starts_with($path, '/') ? rtrim($path, '/') : base_path($path);
    }

    private function generate(ContractRepository $contracts, string $directory): int
    {
        if (! class_exists(Scramble::class)) {
            throw new RuntimeException('Install development dependencies to generate contracts.');
        }
        Scramble::preventSchema(UnknownType::class, throw: false);
        $config = Scramble::getGeneratorConfig('default');
        $result = app(Generator::class)->generate($config);
        $spec = $contracts->decode(json_encode($result->spec($config->openApiVersion()), JSON_THROW_ON_ERROR));
        $spec['servers'] = [['url' => '/'.trim(config('scramble.api_path'), '/')]];
        $diagnostics = $result->diagnostics()->map(function ($diagnostic): array {
            $data = $diagnostic->toArray();
            unset($data['key']);

            return $data;
        })->values()->all();
        unset($result);
        $diagnostics = array_values($diagnostics);
        if ($diagnostics !== [] && ! $this->option('allow-partial')) {
            throw new RuntimeException('Scramble reported '.count($diagnostics).' diagnostics. Use scramble:analyze --fail-on-unknown to inspect them, or --allow-partial for an explicitly marked draft.');
        }
        $spec = $contracts->withDiagnostics($spec, $diagnostics);
        $contracts->validate($spec);
        $registered = collect(app('router')->getRoutes()->getRoutes())->keyBy(fn ($route): string => $route->getName() ?? '');
        foreach (config('api-contracts.routes') as $name) {
            $route = $registered->get($name) ?? throw new RuntimeException("Approved route disappeared: {$name}");
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $id = 'crm.v2.'.str_replace('laravel-crm.api.v2.', '', $name).'.'.strtolower($method);
                if (! isset($contracts->operations($spec)[$id])) {
                    throw new RuntimeException("Scramble omitted an approved operation: {$id}");
                }
            }
        }
        if ($this->option('against')) {
            $changes = $contracts->changes($contracts->read((string) $this->option('against')), $spec);
            if ($this->option('fail-on-breaking') && $changes['requires_review']) {
                $this->emit($contracts, $changes);

                return self::FAILURE;
            }
        }
        File::ensureDirectoryExists($directory);
        $lock = fopen($directory.'/.generate.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another contract writer is active.');
        }
        try {
            if (is_file($directory.'/openapi.json')) {
                $contracts->verify($directory);
                $previous = $contracts->read($directory.'/openapi.json');
                $snapshot = $directory.'/revisions/'.$contracts->hash($previous);
                File::ensureDirectoryExists($snapshot);
                File::put($snapshot.'/openapi.json', $contracts->json($previous));
            }
            $git = new Process(['git', 'rev-parse', 'HEAD'], base_path());
            $git->mustRun();
            $status = new Process(['git', 'status', '--porcelain', '--untracked-files=normal'], base_path());
            $status->mustRun();
            $version = [
                'contract_sha256' => $contracts->hash($spec), 'source_sha256' => $contracts->sourceHash(),
                'code_revision' => trim($git->getOutput()), 'working_tree_dirty' => trim($status->getOutput()) !== '',
                'status' => 'draft', 'diagnostics_count' => count($diagnostics),
                'diagnostics_sha256' => $contracts->hash($diagnostics),
                'generator' => 'dedoc/scramble', 'generator_version' => InstalledVersions::getPrettyVersion('dedoc/scramble'),
            ];
            foreach (['openapi.json' => $spec, 'operations-index.json' => $contracts->index($spec), 'diagnostics.json' => ['contract_sha256' => $version['contract_sha256'], 'diagnostics' => $diagnostics], 'contract-version.json' => $version] as $file => $data) {
                File::replace($directory.'/'.$file, $contracts->json($data));
                chmod($directory.'/'.$file, 0644);
            }
            $contracts->verify($directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->emit($contracts, $version);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function previous(ContractRepository $contracts, string $directory, string $revision): array
    {
        if ($path = $this->option('against')) {
            return $contracts->read((string) $path);
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $revision)) {
            throw new RuntimeException('Supply a SHA-256 revision or --against=/path/to/previous/openapi.json.');
        }
        $current = $contracts->read($directory.'/openapi.json');
        if ($contracts->hash($current) === $revision) {
            return $current;
        }
        $previous = $contracts->read($directory.'/revisions/'.$revision.'/openapi.json');
        if ($contracts->hash($previous) !== $revision) {
            throw new RuntimeException('Historical snapshot hash mismatch.');
        }

        return $previous;
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    private function sync(ContractRepository $contracts, string $directory, array $version): array
    {
        if (! $this->option('destination') || ! $this->option('allow-partial')) {
            throw new RuntimeException('Sync requires --destination and --allow-partial: generated artifacts are drafts until CI and review approve them.');
        }
        $destination = $this->directory((string) $this->option('destination'));
        if (file_exists($destination)) {
            throw new RuntimeException('Destination already exists; use a new revision directory.');
        }
        File::ensureDirectoryExists($destination);
        foreach (['openapi.json', 'operations-index.json', 'contract-version.json', 'diagnostics.json'] as $file) {
            File::copy($directory.'/'.$file, $destination.'/'.$file);
        }
        $contracts->verify($destination);

        return ['destination' => $destination, 'status' => $version['status']];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function emit(ContractRepository $contracts, array $result): void
    {
        $json = $contracts->json($result);
        if ($path = $this->option('output')) {
            File::put((string) $path, $json);

            return;
        }
        if (strlen($json) > (int) $this->option('max-bytes')) {
            throw new RuntimeException('Result is '.strlen($json).' bytes. Use --output=/path/to/result.json or increase --max-bytes; no contract data was truncated.');
        }
        $this->output->write($json, false, OutputInterface::OUTPUT_RAW);
    }
}
