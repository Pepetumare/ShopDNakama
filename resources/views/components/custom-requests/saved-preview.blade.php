@props(['customRequest', 'admin' => false])
@php
    $data = $customRequest->preview_data;
    $file = $data ? $customRequest->files->sortBy('id')->values()->get($data['file_index'] ?? -1) : null;
    $mugV2 = ($data['version'] ?? 1) === 2 && $customRequest->product_type === 'mug';
    $shirtV2 = ($data['version'] ?? 1) === 2 && in_array($customRequest->product_type, ['shirt_white', 'shirt_black'], true);
    $presetLabels = ['custom' => 'Ajuste manual', 'left_chest' => 'Pecho izquierdo', 'upper_center' => 'Centro superior', 'center' => 'Centro', 'large_front' => 'Grande frontal', 'upper_back' => 'Superior', 'back_center' => 'Centro', 'large_back' => 'Grande espalda'];
@endphp
@if($data && $file?->is_image && $customRequest->product_type !== '3d_print')
<section class="cp-editor cp-saved" x-data="customPreview(@js(['readonly' => true, 'data' => $data, 'url' => route('custom-requests.files.show', $file), 'type' => $customRequest->product_type, 'shape' => $customRequest->shape, 'width' => $customRequest->width, 'height' => $customRequest->height, 'dimensions' => $customRequest->dimensions]))">
    <h3>{{ $admin ? 'Vista previa del cliente' : 'Tu vista previa' }}</h3>
    <x-custom-requests.preview-stage :version="(int) ($data['version'] ?? 1)"/>
    <dl>
        @if($mugV2)
        <dt>Tipo de taza</dt><dd>{{ config('custom_orders.mug_variants.'.$customRequest->variant, $customRequest->variant) }}</dd>
        <dt>Modo de ajuste</dt><dd>{{ ['fit' => 'Ajustar', 'fill' => 'Rellenar', 'stretch' => 'Expandir horizontalmente'][$data['fit_mode']] }}</dd>
        <dt>Orientación de la taza</dt><dd>{{ $data['handle_side'] === 'left' ? 'Asa a la izquierda' : 'Asa a la derecha' }}</dd>
        <dt>Escala horizontal</dt><dd>{{ round($data['scale_x'] * 100, 1) }}%</dd>
        <dt>Escala vertical</dt><dd>{{ round($data['scale_y'] * 100, 1) }}%</dd>
        @endif
        @if($shirtV2)
        <dt>Polera</dt><dd>{{ $customRequest->product_type === 'shirt_black' ? 'Negra' : 'Blanca' }}</dd>
        <dt>Orientación del estampado</dt><dd>{{ $data['orientation'] === 'horizontal' ? 'Horizontal' : 'Vertical' }}</dd>
        <dt>Tamaño del estampado</dt><dd>{{ ['small' => 'Pequeño', 'medium' => 'Mediano', 'large' => 'Grande', 'custom' => 'Manual'][$data['size_preset']] }} · {{ round($data['scale'] * 100, 1) }}%</dd>
        <dt>Ubicación del estampado</dt><dd>{{ $presetLabels[$data['preset']] }}</dd>
        @endif
        <dt>Cara</dt><dd>{{ $data['side'] === 'back' ? 'Espalda' : 'Frontal' }}</dd>
        <dt>Posición</dt><dd>X: {{ round($data['x'] * 100, 1) }}% · Y: {{ round($data['y'] * 100, 1) }}%</dd>
        <dt>{{ $mugV2 ? 'Tamaño general del diseño' : 'Escala' }}</dt><dd>{{ round($data['scale'] * 100, 1) }}% {{ $mugV2 ? 'del ajuste seleccionado' : 'del ancho imprimible' }}</dd>
        <dt>Color del producto</dt><dd>{{ ['black' => 'Negro', 'white' => 'Blanco', 'neutral' => 'Según producto / variante'][$data['color']] }}</dd>
        @if($customRequest->product_type === 'stickers')<dt>Dimensiones seleccionadas</dt><dd>{{ $customRequest->dimensions }}</dd>@endif
        @if($customRequest->product_type === 'patch')<dt>Ancho × alto</dt><dd>{{ $customRequest->width }} × {{ $customRequest->height }} cm</dd>@endif
    </dl>
    <p>La vista previa es referencial. La posición y proporción final pueden ajustarse ligeramente durante la producción.</p>
</section>
@endif
