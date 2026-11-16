<?php

namespace VentureDrake\LaravelCrm\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductMatrixOperation;
use VentureDrake\LaravelCrm\Models\ProductVariation;
use VentureDrake\LaravelCrm\Support\CatalogIdentity as Identity;

/** The public command contract uses external_id throughout, including selections. */
class ProductVariantMatrixService
{
    public const MAX_VARIANTS = 2048;

    public function options(Product $product): array
    {
        return $product->options()->with('values')->get()->map(fn ($o) => [
            'id' => $o->external_id, 'name' => $o->name, 'position' => $o->position, 'active' => $o->active,
            'values' => $o->values->map(fn ($v) => [
                'id' => $v->external_id, 'value' => $v->value, 'position' => $v->position,
                'active' => $v->active, 'swatch_hex' => $v->swatch_hex,
            ])->all(),
        ])->all();
    }

    public function previewNew(array $command): array
    {
        $product = new Product(['external_id' => 'new-product', 'options_version' => 0]);
        $product->id = '00000000-0000-5000-a000-000000000001';

        return $this->publicPlan($this->plan($product, $command));
    }

    public function previewLegacyMapping(Product $product, int $page = 1): array
    {
        return $this->preview($product, ['action' => 'inspect_legacy'], $page);
    }

    public function createSelectedVariants(Product $product, array $selectedValueIds, string $operationKey, bool $restoreArchived = false): array
    {
        $command = ['action' => 'generate', 'selections' => $selectedValueIds, 'restore_archived' => $restoreArchived];

        return DB::transaction(function () use ($product, $command, $operationKey) {
            $product = $this->locked($product);
            $prior = ProductMatrixOperation::where('product_id', $product->id)->where('operation_key', $operationKey)->first();
            if ($prior) {
                abort_unless(Identity::signature($prior->request) === Identity::signature($command), 409, 'IDEMPOTENCY_CONFLICT');

                return $prior->result;
            }
            $preview = $this->preview($product, $command);

            return $this->apply($product, $command, $preview['options_version'], $preview['plan_hash'], $operationKey);
        });
    }

    public function bulkUpdate(Product $product, array $variantIds, array $patch, int $version, ?string $currency = null): void
    {
        DB::transaction(function () use ($product, $variantIds, $patch, $version, $currency) {
            $product = $this->locked($product);
            abort_unless($product->options_version === $version, 409, 'STALE_OPTIONS_VERSION');
            $variants = $product->productVariations()->whereIn('external_id', $variantIds)->get();
            if ($variants->count() !== count(array_unique($variantIds))) {
                Identity::reject('VALUE_NOT_IN_PRODUCT');
            }
            foreach ($variants as $variant) {
                $this->updateVariantDetails($variant, $patch, $currency);
            }
        });
    }

    public function preview(Product $product, array $command, int $page = 1): array
    {
        // A consistent preview is read under the same lock as apply; preview writes nothing.
        return DB::transaction(function () use ($product, $command, $page) {
            return $this->publicPlan($this->plan($this->locked($product), $command), $page);
        });
    }

    public function apply(Product $product, array $command, int $version, string $planHash, string $operationKey): array
    {
        if (($command['action'] ?? null) === 'inspect_legacy') {
            Identity::reject('READ_ONLY_LEGACY_PREVIEW');
        }
        Validator::make(['key' => $operationKey], ['key' => 'required|string|max:100'])->validate();

        return DB::transaction(function () use ($product, $command, $version, $planHash, $operationKey) {
            $product = $this->locked($product);
            $requestHash = hash('sha256', Identity::signature(['command' => $command, 'version' => $version, 'plan_hash' => $planHash]));
            $prior = ProductMatrixOperation::where('product_id', $product->id)->where('operation_key', $operationKey)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_hash, $requestHash), 409, 'IDEMPOTENCY_CONFLICT');

                return $prior->result;
            }
            abort_unless($product->options_version === $version, 409, 'STALE_OPTIONS_VERSION');
            $plan = $this->plan($product, $command);
            abort_unless(hash_equals($plan['plan_hash'], $planHash), 409, 'STALE_PLAN');
            if ($plan['conflicts']) {
                Identity::reject($plan['conflicts'][0]);
            }
            app(ProductCatalogSnapshotService::class)->backfill($product);
            $this->persist($product, $plan);
            $product->increment('options_version');
            $result = $this->publicPlan($plan);
            $result['options_version'] = $product->options_version;
            ProductMatrixOperation::create([
                'team_id' => $product->team_id, 'product_id' => $product->id,
                'operation_key' => $operationKey, 'request_hash' => $requestHash,
                'previous_version' => $version, 'actor_id' => auth()->id(), 'request' => $command, 'result' => $result,
            ]);

            return $result;
        }, 3);
    }

    private function locked(Product $product): Product
    {
        return Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
    }

    private function plan(Product $product, array $command): array
    {
        Validator::make($command, [
            'action' => 'required|in:create_options,add_values,generate,map_legacy,inspect_legacy',
            'strategy' => 'sometimes|in:LEAVE_AS_IS,CREATE,MANAGE_NON_DESTRUCTIVE',
            'options' => 'sometimes|required|array|min:1|max:3',
            'options.*.name' => 'required|string|max:255',
            'options.*.values' => 'required|array|min:1|max:2048',
            'options.*.values.*' => 'required|string|max:255',
            'values' => 'sometimes|required|array|min:1|max:2048',
            'values.*' => 'required|string|max:255',
            'option_id' => 'sometimes|required|uuid',
            'confirm_defaults' => 'sometimes|boolean',
            'restore_archived' => 'sometimes|boolean',
            'selections' => 'sometimes|required|array|min:1|max:2048',
            'selections.*' => 'required|array|min:1|max:3',
            'selections.*.*' => 'required|uuid',
            'mappings' => 'sometimes|required|array|min:1|max:2048',
            'mappings.*.variant_id' => 'required|uuid',
            'mappings.*.values' => 'required|array|min:1|max:3',
            'mappings.*.values.*' => 'required|uuid',
        ])->validate();
        $action = $command['action'];
        $strategy = $command['strategy'] ?? 'LEAVE_AS_IS';
        if (($action === 'create_options' && ! in_array($strategy, ['LEAVE_AS_IS', 'CREATE'], true))
            || ($action === 'add_values' && ! in_array($strategy, ['LEAVE_AS_IS', 'MANAGE_NON_DESTRUCTIVE'], true))) {
            Identity::reject('INVALID_VARIANT_STRATEGY');
        }
        $options = $this->options($product);
        $oldOptions = $options;
        $addedValueIds = [];
        $defaults = [];
        $conflicts = [];
        if ($action === 'create_options') {
            if (empty($command['options'])) {
                Identity::reject('OPTIONS_REQUIRED');
            }
            if (count($options) + count($command['options']) > 3) {
                Identity::reject('OPTION_LIMIT_EXCEEDED');
            }
            foreach ($command['options'] as $input) {
                $id = Identity::proposedId($product->id.':'.$product->options_version, $input['name']);
                $new = ['id' => $id, 'name' => $input['name'], 'position' => count($options) + 1, 'active' => true, 'values' => []];
                foreach ($input['values'] as $label) {
                    $new['values'][] = $this->newValue($id, $label, count($new['values']) + 1);
                }
                $this->uniqueLabels($new['values'], 'value');
                $options[] = $new;
                $defaults[$id] = $new['values'][0]['id'];
            }
            $this->uniqueLabels($options, 'name');
        } elseif ($action === 'add_values') {
            $found = false;
            foreach ($options as &$option) {
                if ($option['id'] !== ($command['option_id'] ?? null)) {
                    continue;
                }
                $found = true;
                if (empty($command['values'])) {
                    Identity::reject('VALUES_REQUIRED');
                }
                foreach ($command['values'] as $label) {
                    $newValue = $this->newValue($option['id'], $label, count($option['values']) + 1, (string) $product->options_version);
                    $option['values'][] = $newValue;
                    $addedValueIds[] = $newValue['id'];
                }
                $this->uniqueLabels($option['values'], 'value');
            }
            unset($option);
            if (! $found) {
                Identity::reject('VALUE_NOT_IN_OPTION');
            }
        }
        $variants = $product->productVariations()->withTrashed()->with(['selections.option', 'selections.value', 'productPrices'])->get();
        $references = [];
        foreach (['deal_products', 'quote_products', 'order_products', 'invoice_lines', 'purchase_order_lines'] as $suffix) {
            foreach (DB::table(config('laravel-crm.db_table_prefix').$suffix)->where('product_id', $product->id)
                ->whereNotNull('product_variation_id')->selectRaw('product_variation_id, count(*) as total')->groupBy('product_variation_id')->get() as $ref) {
                $references[$ref->product_variation_id][$suffix] = (int) $ref->total;
            }
        }
        $rows = [];
        $keys = [];
        $unmapped = 0;
        foreach ($variants as $variant) {
            $before = $variant->selections->mapWithKeys(fn ($s) => [$s->option->external_id => $s->value->external_id])->all();
            $after = $before;
            $state = 'unaffected';
            if ($variant->configuration_status === 'configured') {
                $after += $defaults;
                $state = $defaults ? 'preserved' : 'unaffected';
                if ($defaults && ! ($command['confirm_defaults'] ?? false)) {
                    $conflicts[] = 'DEFAULT_ASSIGNMENT_CONFIRMATION_REQUIRED';
                }
            } else {
                $state = 'requires_mapping';
                $unmapped++;
            }
            if ($action === 'map_legacy') {
                foreach ($command['mappings'] ?? [] as $mapping) {
                    if ($mapping['variant_id'] === $variant->external_id) {
                        if ($variant->configuration_status === 'configured') {
                            Identity::reject('ALREADY_CONFIGURED');
                        }
                        $after = $this->pairs($options, $mapping['values']);
                        $state = 'preserved';
                        $unmapped--;
                    }
                }
            }
            if ($after) {
                $this->assertPairs($options, $after);
                $key = Identity::key($after);
                if (isset($keys[$key])) {
                    $conflicts[] = 'DUPLICATE_VARIANT_COMBINATION';
                }
                $keys[$key] = count($rows);
            }
            $rows[] = [
                'existing_variant_id' => $variant->external_id, 'status' => $state,
                'before' => $before, 'after' => $after, 'sku' => $variant->code, 'barcode' => $variant->barcode,
                'document_references' => $references[$variant->id] ?? [],
                'name' => $variant->name, 'archived' => $variant->trashed(),
                'active' => $variant->active, 'published' => $variant->published,
                'before_label' => $variant->name, 'after_label' => $after ? $this->label($options, $after) : $variant->name,
                'availability' => 'unknown', 'reference_impact' => 'identities_and_foreign_keys_preserved',
                'prices' => $variant->productPrices->map(fn ($p) => ['currency' => $p->currency, 'unit_price' => $p->unit_price === null ? null : $p->unit_price / 100])->all(),
            ];
        }
        if ($action === 'map_legacy') {
            if (empty($command['mappings'])) {
                Identity::reject('MAPPINGS_REQUIRED');
            }
            $seen = [];
            foreach ($command['mappings'] as $mapping) {
                if (! $variants->contains('external_id', $mapping['variant_id']) || isset($seen[$mapping['variant_id']])) {
                    Identity::reject('VALUE_NOT_IN_PRODUCT');
                }
                $seen[$mapping['variant_id']] = true;
            }
        }
        $generate = $action === 'generate' || ($action === 'create_options' && $strategy === 'CREATE')
            || ($action === 'add_values' && $strategy === 'MANAGE_NON_DESTRUCTIVE');
        if ($generate && $unmapped) {
            $conflicts[] = 'LEGACY_MAPPING_REQUIRED';
        }
        if ($generate && ! $unmapped) {
            if (! $options) {
                Identity::reject('OPTIONS_REQUIRED');
            }
            $combinations = isset($command['selections'])
                ? array_map(fn ($s) => $this->pairs($options, $s), $command['selections'])
                : $this->combinations($options);
            foreach ($combinations as $pairs) {
                if ($action === 'add_values' && ! array_intersect(array_values($pairs), $addedValueIds)) {
                    continue;
                }
                if ($action === 'create_options' && $variants->isNotEmpty() && $oldOptions
                    && ! array_filter($defaults, fn ($default, $optionId) => $pairs[$optionId] !== $default, ARRAY_FILTER_USE_BOTH)) {
                    continue;
                }
                $key = Identity::key($pairs);
                if (isset($keys[$key])) {
                    $index = $keys[$key];
                    if (Identity::signature($rows[$index]['after']) !== Identity::signature($pairs)) {
                        Identity::reject('COMBINATION_HASH_COLLISION');
                    }
                    if ($rows[$index]['archived'] && isset($command['selections']) && ! ($command['restore_archived'] ?? false)) {
                        $conflicts[] = 'ARCHIVED_COMBINATION_REQUIRES_RESTORE';
                        $rows[$index]['status'] = 'blocked_conflict';
                    }
                    if ($rows[$index]['archived'] && ($command['restore_archived'] ?? false)) {
                        $rows[$index]['status'] = 'reactivated';
                    }

                    continue;
                }
                $keys[$key] = count($rows);
                $rows[] = [
                    'existing_variant_id' => null, 'status' => 'created', 'before' => [], 'after' => $pairs,
                    'before_label' => null, 'after_label' => $this->label($options, $pairs),
                    'sku' => null, 'barcode' => null, 'availability' => 'unknown',
                    'reference_impact' => 'new_identity_without_inventory', 'prices' => [],
                ];
            }
        }
        $created = count(array_filter($rows, fn ($r) => $r['status'] === 'created'));
        if ($variants->count() + $created > self::MAX_VARIANTS) {
            Identity::reject('VARIANT_LIMIT_EXCEEDED');
        }
        $plan = [
            'product_id' => $product->external_id, 'options_version' => $product->options_version,
            'options' => $options, 'defaults' => $defaults, 'command' => $command,
            'counts' => ['before' => $variants->count(), 'created' => $created,
                'preserved' => count(array_filter($rows, fn ($r) => in_array($r['status'], ['preserved', 'unaffected']))),
                'conflicts' => count(array_unique($conflicts)),
                'unaffected' => count(array_filter($rows, fn ($r) => $r['status'] === 'unaffected')),
                'requires_mapping' => $unmapped, 'reactivated' => count(array_filter($rows, fn ($r) => $r['status'] === 'reactivated')),
                'limit' => self::MAX_VARIANTS, 'new_without_inventory' => $created],
            'conflicts' => array_values(array_unique($conflicts)),
            'warnings' => array_values(array_filter([
                $defaults && $variants->isNotEmpty() ? 'Confirmar atributos actuales no reconstruye atributos de ventas históricas.' : null,
                $unmapped ? 'Mapeo manual pendiente; generación y venta legacy con opciones bloqueadas.' : null,
                count($rows) > 100 ? 'Más de 100 variantes; detalle paginado.' : null,
                'Disponibilidad no acreditada; no se asigna ni duplica stock del producto padre.',
            ])), 'rows' => $rows,
        ];
        $plan['plan_hash'] = hash('sha256', Identity::signature($plan));

        return $plan;
    }

    private function persist(Product $product, array $plan): void
    {
        $options = [];
        $values = [];
        foreach ($plan['options'] as $o) {
            $option = $product->options()->where('external_id', $o['id'])->first();
            $option ??= $product->options()->create([
                'team_id' => $product->team_id, 'external_id' => $o['id'], 'name' => $o['name'], 'position' => $o['position'],
            ]);
            $options[$o['id']] = $option;
            foreach ($o['values'] as $v) {
                $value = $option->values()->where('external_id', $v['id'])->first();
                $value ??= $option->values()->create([
                    'team_id' => $product->team_id, 'product_id' => $product->id, 'external_id' => $v['id'],
                    'value' => $v['value'], 'position' => $v['position'],
                ]);
                $values[$v['id']] = $value;
            }
        }
        foreach ($plan['rows'] as $row) {
            if (in_array($row['status'], ['requires_mapping', 'unaffected'])) {
                continue;
            }
            $variant = $row['existing_variant_id']
                ? $product->productVariations()->withTrashed()->where('external_id', $row['existing_variant_id'])->firstOrFail()
                : new ProductVariation(['product_id' => $product->id, 'team_id' => $product->team_id, 'active' => true, 'published' => false]);
            if ($row['status'] === 'reactivated') {
                $variant->restore();
                $variant->active = true;
                $variant->published = true;
            }
            $pairs = $row['after'];
            $variant->combination_signature = Identity::signature($pairs);
            $variant->combination_key = Identity::key($pairs);
            $variant->configuration_status = 'configured';
            $variant->name = $this->label($plan['options'], $pairs);
            $variant->save();
            foreach ($pairs as $optionId => $valueId) {
                $variant->selections()->updateOrCreate(['product_option_id' => $options[$optionId]->id], [
                    'team_id' => $product->team_id, 'product_id' => $product->id,
                    'product_option_value_id' => $values[$valueId]->id,
                ]);
            }
        }
    }

    private function publicPlan(array $plan, int $page = 1): array
    {
        $rows = $plan['rows'];
        unset($plan['rows']);
        $page = max(1, $page);
        $plan['details'] = array_slice($rows, ($page - 1) * 100, 100);
        $plan['pagination'] = ['page' => $page, 'per_page' => 100, 'total' => count($rows)];

        return $plan;
    }

    private function newValue(string $optionId, string $label, int $position, string $generation = 'initial'): array
    {
        return ['id' => Identity::proposedId($optionId.':'.$generation, $label), 'value' => $label, 'position' => $position, 'active' => true, 'swatch_hex' => null];
    }

    private function uniqueLabels(array $rows, string $field): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $key = Identity::normalize($row[$field]);
            if ($key === '' || isset($seen[$key])) {
                Identity::reject('DUPLICATE_OPTION_'.strtoupper($field));
            }
            $seen[$key] = true;
        }
    }

    private function pairs(array $options, array $ids): array
    {
        $pairs = [];
        foreach ($ids as $id) {
            $found = false;
            foreach ($options as $o) {
                foreach ($o['values'] as $v) {
                    if ($v['id'] === $id) {
                        if (isset($pairs[$o['id']]) || ! $o['active'] || ! $v['active']) {
                            Identity::reject('VALUE_NOT_IN_OPTION');
                        }
                        $pairs[$o['id']] = $id;
                        $found = true;
                    }
                }
            }
            if (! $found) {
                Identity::reject('VALUE_NOT_IN_OPTION');
            }
        }
        $this->assertPairs($options, $pairs);

        return $pairs;
    }

    private function assertPairs(array $options, array $pairs): void
    {
        if (count($options) !== count($pairs)) {
            Identity::reject('EXACTLY_ONE_VALUE_PER_OPTION_REQUIRED');
        }
        foreach ($options as $o) {
            if (! isset($pairs[$o['id']]) || ! in_array($pairs[$o['id']], array_column($o['values'], 'id'), true)) {
                Identity::reject('VALUE_NOT_IN_OPTION');
            }
        }
    }

    private function combinations(array $options): array
    {
        $result = [[]];
        foreach ($options as $o) {
            $next = [];
            foreach ($result as $pairs) {
                foreach ($o['values'] as $v) {
                    if ($o['active'] && $v['active']) {
                        $next[] = $pairs + [$o['id'] => $v['id']];
                        if (count($next) > self::MAX_VARIANTS) {
                            Identity::reject('VARIANT_LIMIT_EXCEEDED');
                        }
                    }
                }
            }
            $result = $next;
        }

        return $result;
    }

    private function label(array $options, array $pairs): string
    {
        return implode(' / ', array_map(function ($o) use ($pairs) {
            foreach ($o['values'] as $v) {
                if ($v['id'] === ($pairs[$o['id']] ?? null)) {
                    return $v['value'];
                }
            }

            return '';
        }, $options));
    }

    /** All detail/label/state writers serialize with matrix generation. */
    public function updateVariantDetails(ProductVariation $variation, array $patch, ?string $currency = null): ProductVariation
    {
        return DB::transaction(function () use ($variation, $patch, $currency) {
            $product = $this->locked($variation->product);
            $variation = $product->productVariations()->withTrashed()->whereKey($variation->id)->firstOrFail();
            if (isset($patch['options_version'])) {
                abort_unless((int) $patch['options_version'] === $product->options_version, 409, 'STALE_OPTIONS_VERSION');
            }
            if (array_key_exists('image_file_id', $patch) && $patch['image_file_id'] === '') {
                $patch['image_file_id'] = null;
            }
            if (array_key_exists('image_file_id', $patch) && $patch['image_file_id'] !== null) {
                $file = File::where('external_id', $patch['image_file_id'])->where('team_id', $product->team_id)->first();
                if (! $file || ! in_array($file->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'], true)
                    || $file->fileable_type !== $product->getMorphClass() || (string) $file->fileable_id !== (string) $product->id) {
                    Identity::reject('INVALID_VARIANT_IMAGE');
                }
                $patch['image_file_id'] = $file->id;
            }
            if ($variation->configuration_status === 'configured') {
                unset($patch['name']);
            }
            if (($patch['published'] ?? false) && $product->options()->exists()) {
                if ($variation->configuration_status !== 'configured' || $variation->trashed()
                    || $variation->selections()->whereHas('value', fn ($q) => $q->where('active', false))->exists()) {
                    Identity::reject('VARIANT_NOT_SELLABLE');
                }
            }
            app(ProductCatalogSnapshotService::class)->backfill($product);
            $variation->fill(array_intersect_key($patch, array_flip(['name', 'code', 'barcode', 'active', 'published', 'order', 'image_file_id'])))->save();
            if (array_key_exists('unit_price', $patch)) {
                app(ProductService::class)->setVariationPrice($variation, $patch['unit_price'], $currency);
            }
            $product->increment('options_version');

            return $variation->refresh();
        });
    }

    public function archiveVariant(ProductVariation $variation, ?int $version = null): void
    {
        DB::transaction(function () use ($variation, $version) {
            $product = $this->locked($variation->product);
            abort_if($version !== null && $product->options_version !== $version, 409, 'STALE_OPTIONS_VERSION');
            $variation->update(['active' => false, 'published' => false]);
            $variation->delete();
            $product->increment('options_version');
        });
    }

    public function restoreVariant(ProductVariation $variation, ?int $version = null): ProductVariation
    {
        return DB::transaction(function () use ($variation, $version) {
            $product = $this->locked($variation->product);
            abort_if($version !== null && $product->options_version !== $version, 409, 'STALE_OPTIONS_VERSION');
            $variation->restore();
            // Republishing remains a separate explicit patch.
            $variation->update(['active' => true, 'published' => false]);
            $product->increment('options_version');

            return $variation->refresh();
        });
    }

    public function patchOption(Product $product, string $optionId, array $patch, ?string $valueId = null): void
    {
        DB::transaction(function () use ($product, $optionId, $patch, $valueId) {
            $product = $this->locked($product);
            abort_unless((int) $patch['options_version'] === $product->options_version, 409, 'STALE_OPTIONS_VERSION');
            abort_unless(Str::isUuid($optionId) && ($valueId === null || Str::isUuid($valueId)), 404);
            $option = $product->options()->where('external_id', $optionId)->firstOrFail();
            $model = $valueId ? $option->values()->where('external_id', $valueId)->firstOrFail() : $option;
            $field = $valueId ? 'value' : 'name';
            if (isset($patch[$field])) {
                $query = $valueId ? $option->values() : $product->options();
                if ($query->where('normalized_'.$field, Identity::normalize($patch[$field]))->where('id', '!=', $model->id)->exists()) {
                    Identity::reject('DUPLICATE_OPTION_'.strtoupper($field));
                }
            }
            if (! $valueId && isset($patch['active']) && ! $patch['active']) {
                Identity::reject('CANNOT_REMOVE_USED_OPTION');
            }
            app(ProductCatalogSnapshotService::class)->backfill($product);
            if (isset($patch['position'])) {
                $siblings = ($valueId ? $option->values() : $product->options())->get()->reject(fn ($item) => $item->id === $model->id)->values()->all();
                array_splice($siblings, min(count($siblings), max(0, $patch['position'] - 1)), 0, [$model]);
                foreach ($siblings as $index => $sibling) {
                    $sibling->update(['position' => $index + 1]);
                }
                unset($patch['position']);
            }
            $model->update(array_intersect_key($patch, array_flip($valueId ? [$field, 'active', 'swatch_hex'] : [$field, 'active'])));
            if ($valueId && isset($patch['active']) && ! $patch['active']) {
                $product->productVariations()->whereHas('selections', fn ($q) => $q->where('product_option_value_id', $model->id))->update(['published' => false]);
            }
            // Labels follow current display order, identity always follows stable IDs.
            $options = $this->options($product);
            foreach ($product->productVariations()->withTrashed()->where('configuration_status', 'configured')->with(['selections.option', 'selections.value'])->get() as $variant) {
                $pairs = $variant->selections->mapWithKeys(fn ($s) => [$s->option->external_id => $s->value->external_id])->all();
                $variant->update(['name' => $this->label($options, $pairs)]);
            }
            $product->increment('options_version');
        });
    }
}
