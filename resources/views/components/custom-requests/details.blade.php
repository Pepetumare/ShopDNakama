@props(['customRequest'])
<h2>Datos del cliente</h2>
<dl class="account-order-details"><div><dt>Nombre</dt><dd>{{ $customRequest->customer_name }}</dd></div><div><dt>Email</dt><dd>{{ $customRequest->customer_email }}</dd></div><div><dt>Teléfono</dt><dd>{{ $customRequest->customer_phone ?: 'No registrado' }}</dd></div></dl>
<h2>Solicitud</h2>
@if($customRequest->product_type === '3d_print')
<h2>IMPRESIÓN 3D</h2>
<dl class="account-order-details">
    <div><dt>Proyecto / pieza</dt><dd>{{ $customRequest->project_name }}</dd></div>
    <div><dt>Tipo de pieza</dt><dd>{{ \App\Models\CustomRequest::PIECE_TYPES[$customRequest->piece_type] ?? $customRequest->piece_type }}</dd></div>
    <div><dt>Dimensiones (ancho × alto × profundidad)</dt><dd>{{ $customRequest->width }} × {{ $customRequest->height }} × {{ $customRequest->depth }} {{ $customRequest->dimension_unit }}</dd></div>
    <div><dt>Material</dt><dd>{{ $customRequest->material ?: 'A recomendar' }}</dd></div>
    <div><dt>Funcional / decorativa</dt><dd>{{ \App\Models\CustomRequest::USAGE_TYPES[$customRequest->usage_type] ?? $customRequest->usage_type }}</dd></div>
</dl>
<h3>Descripción detallada</h3><p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $customRequest->description }}</p>
<h3>Referencias</h3><p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $customRequest->references ?: 'Sin referencias adicionales.' }}</p>
@endif
<dl class="account-order-details">
    <div><dt>Número</dt><dd>{{ $customRequest->request_number }}</dd></div>
    <div><dt>Fecha</dt><dd>{{ $customRequest->created_at->format('d/m/Y H:i') }}</dd></div>
    <div><dt>Tipo</dt><dd>{{ $customRequest->product_label }}</dd></div>
    <div><dt>Estado</dt><dd>{{ $customRequest->status_label }}</dd></div>
    @if($customRequest->product_type === 'badges')
        <div aria-label="Medida estándar"><dt>Medida</dt><dd>Estándar</dd></div>
    @elseif($customRequest->is_patch)
        <div><dt>Ancho</dt><dd>{{ $customRequest->width ?? 'No registrado' }} @if($customRequest->width !== null){{ $customRequest->dimension_unit ?: 'cm' }}@endif</dd></div>
        <div><dt>Alto</dt><dd>{{ $customRequest->height ?? 'No registrado' }} @if($customRequest->height !== null){{ $customRequest->dimension_unit ?: 'cm' }}@endif</dd></div>
        <div><dt>Forma</dt><dd>{{ \App\Models\CustomRequest::PATCH_SHAPES[$customRequest->shape] ?? ($customRequest->shape ?: 'No registrada') }}</dd></div>
        @if($customRequest->border_color)<div><dt>Color de borde</dt><dd>{{ $customRequest->border_color }}</dd></div>@endif
        @if($customRequest->variant)<div><dt>Configuración registrada</dt><dd>{{ $customRequest->variant }}</dd></div>@endif
        @if($customRequest->print_position)<div><dt>Ubicación registrada</dt><dd>{{ $customRequest->print_position }}</dd></div>@endif
    @else
    @foreach(['variant' => 'Variante', 'color' => 'Color', 'size' => 'Talla / tamaño', 'print_position' => 'Ubicación del diseño', 'dimensions' => 'Dimensiones'] as $field => $label)
    <div><dt>{{ $label }}</dt><dd>{{ $field === 'variant' && $customRequest->product_type === 'mug' ? (config('custom_orders.mug_variants')[$customRequest->variant] ?? $customRequest->variant) : ($customRequest->$field ?: '—') }}</dd></div>
    @endforeach
    @endif
    <div><dt>Cantidad</dt><dd>{{ $customRequest->quantity }}</dd></div>
    <div><dt>Precio cotizado</dt><dd>@if($customRequest->quoted_price !== null)<x-store.price :amount="$customRequest->quoted_price"/>@else Pendiente de cotización @endif</dd></div>
</dl>
<h3>Instrucciones / descripción</h3><p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $customRequest->instructions ?: 'Sin instrucciones adicionales.' }}</p>
<h2>Diseño</h2>
@foreach($customRequest->files as $file)
<div class="custom-design">
    @if($file->is_image)<a href="{{ route('custom-requests.files.show', $file) }}" target="_blank" rel="noopener"><img src="{{ route('custom-requests.files.show', $file) }}" alt="Diseño enviado por el cliente" style="max-width:100%;max-height:600px;object-fit:contain"></a>@elseif($file->file_type === 'model_3d')<h3>Archivos 3D</h3><p>Modelo 3D adjunto · {{ strtoupper($file->extension) }}</p>@else<span class="custom-file-icon" aria-label="Archivo PDF">PDF</span><p>Archivo PDF seleccionado</p>@endif
    <p style="overflow-wrap:anywhere">{{ $file->original_name }} · {{ number_format($file->size / 1024, 1, ',', '.') }} KB</p>
    <a href="{{ route('custom-requests.files.show', [$file, 'download' => 1]) }}">Descargar diseño</a>
</div>
@endforeach
