<div class="grid gap-3">
    <h3>Variantes — {{ count($options) ? 'Con opciones' : 'Sin opciones estructuradas' }}</h3>
    <p>Hasta 3 opciones y 2.048 variantes. La disponibilidad no está acreditada: sin control de inventario por variante.</p>
    @if($total > 100)<p class="text-warning">Más de 100 variantes. Edita por páginas o selecciona un lote.</p>@endif
    @error('catalog')<p class="text-error">{{ $message }}</p>@enderror
    @foreach($errors->all() as $error)<p class="text-error">{{ $error }}</p>@endforeach
    @if($message)<p role="status">{{ $message }}</p>@endif
    <button type="button" class="btn btn-sm" wire:click="reload">Recargar catálogo (versión {{ $version }})</button>
    @if(!$options)
        <div class="flex gap-2 items-end"><x-mary-input label="Variante de texto libre (compatibilidad)" wire:model="legacyName" /><button type="button" class="btn btn-sm" wire:click="createLegacyVariation">Agregar variante manual</button></div>
    @endif
    @foreach($options as $option)
        <div class="grid gap-2 border-b pb-3" wire:key="option-{{ $option['id'] }}">
            <div class="flex gap-2 items-end">
                <x-mary-input label="Opción" wire:model="optionEdits.{{ $option['id'] }}" />
                <button type="button" class="btn btn-sm" wire:click="saveOption('{{ $option['id'] }}')">Guardar nombre</button>
                <button type="button" class="btn btn-sm" wire:click="moveOption('{{ $option['id'] }}', {{ $option['position'] - 1 }})">↑</button>
                <button type="button" class="btn btn-sm" wire:click="moveOption('{{ $option['id'] }}', {{ $option['position'] + 1 }})">↓</button>
            </div>
            @foreach($option['values'] as $value)
                <div class="flex flex-wrap gap-2 items-end" wire:key="value-{{ $value['id'] }}">
                    <x-mary-input label="Valor" wire:model="valueEdits.{{ $value['id'] }}.value" />
                    <x-mary-input label="Color #RRGGBB (opcional)" wire:model="valueEdits.{{ $value['id'] }}.swatch_hex" />
                    <button type="button" class="btn btn-sm" wire:click="saveOption('{{ $option['id'] }}', '{{ $value['id'] }}')">Guardar valor</button>
                    <button type="button" class="btn btn-sm" wire:click="moveOption('{{ $option['id'] }}', {{ $value['position'] - 1 }}, '{{ $value['id'] }}')">↑</button>
                    <button type="button" class="btn btn-sm" wire:click="moveOption('{{ $option['id'] }}', {{ $value['position'] + 1 }}, '{{ $value['id'] }}')">↓</button>
                    <button type="button" class="btn btn-sm" wire:click="toggleValue('{{ $option['id'] }}', '{{ $value['id'] }}', {{ $value['active'] ? 'false' : 'true' }})">{{ $value['active'] ? 'Desactivar y despublicar variantes' : 'Reactivar valor' }}</button>
                </div>
            @endforeach
        </div>
    @endforeach
    <label>Operación
        <select wire:model.live="action" class="select select-bordered">
            <option value="create_options">Agregar opciones</option>
            <option value="add_values">Agregar valores</option>
            <option value="generate">Generar combinaciones faltantes / seleccionadas</option>
            <option value="map_legacy">Mapear variantes históricas de esta página</option>
        </select>
    </label>
    @if($action === 'create_options')
        @foreach($drafts as $i => $draft)
            <div class="grid lg:grid-cols-2 gap-2" wire:key="draft-{{ $i }}">
                <x-mary-input label="Nombre (Color, Talla, Material…)" wire:model="drafts.{{ $i }}.name" />
                <x-mary-input label="Valores separados por comas" wire:model="drafts.{{ $i }}.values" />
                <button type="button" class="btn btn-sm" wire:click="removeDraft({{ $i }})">Quitar opción pendiente</button>
            </div>
        @endforeach
        <button type="button" class="btn btn-sm" wire:click="addDraft">Agregar otra opción (máximo 3)</button>
    @elseif($action === 'add_values')
        <select wire:model="optionId" class="select select-bordered"><option value="">Elegir opción</option>@foreach($options as $o)<option value="{{ $o['id'] }}">{{ $o['name'] }}</option>@endforeach</select>
        <x-mary-input label="Nuevos valores separados por comas" wire:model="newValues" />
    @elseif($action === 'generate')
        <x-mary-toggle label="Crear solo las combinaciones seleccionadas" wire:model.live="selectedOnly" />
        @if($selectedOnly)
            @foreach($options as $o)
                <label>{{ $o['name'] }}<select wire:model="selection.{{ $o['id'] }}" class="select select-bordered"><option value="">Elegir valor</option>@foreach($o['values'] as $v)@if($v['active'])<option value="{{ $v['id'] }}">{{ $v['value'] }}</option>@endif @endforeach</select></label>
            @endforeach
            <button type="button" class="btn btn-sm" wire:click="queueSelection">Añadir combinación al lote (máximo 100)</button>
            @foreach($queuedSelections as $index => $ids)
                <div class="flex gap-2"><span>
                    @foreach($options as $o)@foreach($o['values'] as $v)@if(in_array($v['id'], $ids)){{ $o['name'] }}: {{ $v['value'] }} @endif @endforeach @endforeach
                </span><button type="button" class="btn btn-sm" wire:click="removeSelection({{ $index }})">Quitar</button></div>
            @endforeach
        @endif
    @endif
    @if(in_array($action, ['create_options', 'add_values']))
        <select wire:model="strategy" class="select select-bordered">
            <option value="LEAVE_AS_IS">Conservar existentes</option>
            <option value="{{ $action === 'add_values' ? 'MANAGE_NON_DESTRUCTIVE' : 'CREATE' }}">Generar combinaciones faltantes</option>
        </select>
    @endif
    <p class="text-warning">Asignar un nuevo valor a una identidad histórica cambia su significado actual. No conocemos su atributo pasado. Si es desconocido, usa «Sin especificar» o clasifica manualmente.</p>
    <x-mary-toggle label="Confirmo la asignación del primer valor de cada opción nueva a las variantes configuradas existentes" wire:model="confirmDefaults" />
    <x-mary-toggle label="Restaurar y republicar explícitamente combinaciones archivadas solicitadas" wire:model="restoreArchived" />
    <button type="button" class="btn btn-outline" wire:click="previewChanges">Previsualizar variantes</button>
    @if($preview)
        <div class="grid gap-2 border p-3">
            <p>Existentes: {{ $preview['counts']['before'] }} · Nuevas: {{ $preview['counts']['created'] }} · Conservadas: {{ $preview['counts']['preserved'] }} · Pendientes de mapeo: {{ $preview['counts']['requires_mapping'] }} · Límite: 2.048</p>
            @foreach($preview['options'] as $o)@if(isset($preview['defaults'][$o['id']]))<p>Nuevo valor predeterminado: {{ $o['name'] }} = {{ $o['values'][0]['value'] }}</p>@endif @endforeach
            @foreach($preview['warnings'] as $warning)<p class="text-warning">{{ $warning }}</p>@endforeach
            @foreach($preview['conflicts'] as $conflict)<p class="text-error">{{ $conflict }}</p>@endforeach
            <div class="overflow-x-auto"><table class="table"><thead><tr><th>Identidad existente</th><th>Anterior → Propuesta</th><th>Resultado</th><th>SKU</th><th>Disponibilidad</th></tr></thead><tbody>
            @foreach($preview['details'] as $detail)<tr><td>{{ $detail['existing_variant_id'] ?? 'Nueva' }}</td><td>{{ $detail['before_label'] ?? 'Nueva' }} → {{ $detail['after_label'] }}</td><td>{{ $detail['status'] }}</td><td>{{ $detail['sku'] }}</td><td>Sin control</td></tr>@endforeach
            </tbody></table></div>
            <p>Detalle: {{ count($preview['details']) }} de {{ $preview['pagination']['total'] }} · página {{ $preview['pagination']['page'] }}. Las nuevas variantes nacen despublicadas.</p>
            <div class="flex gap-2"><button type="button" class="btn btn-sm" wire:click="changePreviewPage(-1)">Anterior</button><button type="button" class="btn btn-sm" wire:click="changePreviewPage(1)">Siguiente</button></div>
            @if(!$preview['conflicts'])<button type="button" class="btn btn-primary" wire:click="applyChanges" wire:loading.attr="disabled">Aplicar plan previsualizado</button>@endif
        </div>
    @endif
    <x-mary-select label="Moneda de los precios" wire:model.live="currency" :options="\VentureDrake\LaravelCrm\Http\Helpers\SelectOptions\currencyOptions()" />
    <div class="flex gap-2"><input type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/avif" wire:model="uploadedImage" /><button type="button" class="btn btn-sm" wire:click="uploadImage">Agregar fotografía al catálogo (máximo 5 MB)</button></div>
    <div class="overflow-x-auto"><table class="table"><thead><tr><th>Lote</th><th>Combinación / mapeo</th><th>SKU / barcode</th><th>Precio {{ $currency }}</th><th>Estado / imagen</th><th>Acciones</th></tr></thead><tbody>
    @foreach($rows as $id => $row)
        <tr wire:key="variant-{{ $id }}">
            <td><input type="checkbox" wire:model="checked" value="{{ $id }}" @disabled($row['archived']) /></td>
            <td>@if($row['legacy'])<x-mary-input label="Nombre legado" wire:model="rows.{{ $id }}.name" />@else{{ $row['name'] }}@endif @if($row['legacy'])<span class="text-warning">Pendiente de mapeo</span>@endif
                @if($action === 'map_legacy' && $row['legacy'])
                    @foreach($options as $o)<label>{{ $o['name'] }}<select wire:model="mappings.{{ $id }}.{{ $o['id'] }}" class="select select-bordered"><option value="">Sin clasificar</option>@foreach($o['values'] as $v)@if($v['active'])<option value="{{ $v['id'] }}">{{ $v['value'] }}</option>@endif @endforeach</select></label>@endforeach
                @endif
            </td>
            <td><x-mary-input label="SKU" wire:model="rows.{{ $id }}.code" /><x-mary-input label="Barcode" wire:model="rows.{{ $id }}.barcode" /></td>
            <td><x-mary-input label="Precio propio (unidades mayores)" wire:model="rows.{{ $id }}.unit_price" /><p>{{ $row['unit_price'] === null ? 'Heredado: ' : 'Propio: ' }}{{ $row['effective_price'] === null ? 'No disponible' : number_format($row['effective_price'] / 100, 2) }}</p><p>Stock: sin control</p></td>
            <td><x-mary-toggle label="Activo" wire:model="rows.{{ $id }}.active" /><x-mary-toggle label="Publicado" wire:model="rows.{{ $id }}.published" /><label>Fotografía<select wire:model="rows.{{ $id }}.image_file_id" class="select select-bordered"><option value="">Sin fotografía</option>@foreach($images as $image)<option value="{{ $image['id'] }}">{{ $image['name'] }}</option>@endforeach</select></label></td>
            <td>@if($row['archived'])<button type="button" class="btn btn-sm" wire:click="restore('{{ $id }}')">Restaurar identidad</button>@else<button type="button" class="btn btn-sm" wire:click="saveVariant('{{ $id }}')">Guardar</button><button type="button" class="btn btn-sm" wire:click="archive('{{ $id }}')">Archivar</button>@endif</td>
        </tr>
    @endforeach
    </tbody></table></div>
    <div class="flex gap-2"><button type="button" class="btn btn-sm" wire:click="changePage(-1)">Anterior</button><span>Página {{ $page }} · {{ $total }} variantes</span><button type="button" class="btn btn-sm" wire:click="changePage(1)">Siguiente</button></div>
    <div class="flex gap-2"><select wire:model.live="bulkField" class="select select-bordered"><option value="published">Publicación</option><option value="active">Activo</option><option value="code">SKU</option><option value="barcode">Barcode</option><option value="unit_price">Precio en {{ $currency }}</option></select>@if(in_array($bulkField, ['active', 'published']))<x-mary-toggle label="Valor para el lote seleccionado" wire:model="bulkValue" />@else<x-mary-input label="Valor para el lote (vacío retira el dato)" wire:model="bulkText" />@endif<button type="button" class="btn btn-sm" wire:click="bulkSave">Aplicar solo este campo al lote</button></div>
</div>
