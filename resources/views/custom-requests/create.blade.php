@extends('layouts.store')
@section('title', 'Personalizados | Shop D. Nakama')
@section('content')
<div class="shop-container custom-page" x-data="customOrderForm(@js(old('product_type', '')), @js(old('variant', 'white')), @js(old('size', '')), @js(old('print_position', '')))">
    <header class="custom-heading"><p class="eyebrow">TU IDEA · TU ESTILO · TU TRIPULACIÓN</p><h1>Personalizados</h1><p>Dale vida a tu diseño favorito. Elige un producto y cuéntanos cómo lo imaginas.</p><p>Revisaremos tu solicitud y prepararemos una cotización.</p></header>
    <div class="custom-cards" aria-label="Selecciona un producto">
        @foreach(\App\Models\CustomRequest::PRODUCTS as $value => $label)
        <button type="button" class="custom-card" :class="{ 'is-selected': product === '{{ $value }}' }" :aria-pressed="product === '{{ $value }}'" @click="choose('{{ $value }}')">
            <span class="custom-card-art custom-art-{{ $value }}"><x-custom-requests.product-art :type="$value"/></span><strong>{{ $label }}</strong><span>Crea algo único →</span>
        </button>
        @endforeach
    </div>
    @guest
    <div class="custom-login"><h2>Tu diseño empieza aquí</h2><p>Inicia sesión para enviar tu solicitud y seguir su estado desde Mi Cuenta.</p><a class="shop-button" href="{{ route('custom-requests.continue') }}">Iniciar sesión y continuar</a></div>
    @endguest
    <form x-cloak x-show="product" id="custom-order-form" x-init="previewDimensions = @js(old('dimensions', '')); previewWidth = @js(old('width', '')); previewHeight = @js(old('height', '')); previewShape = @js(old('shape', ''))" class="custom-form" method="post" action="{{ route('custom-requests.store') }}" enctype="multipart/form-data">
        @csrf
        <h2>Configura tu personalizado</h2>
        <p>1. Configura tu producto · 2. Sube tu diseño · 3. Solicita una cotización</p>
        <div class="custom-fields">
            <label>Tipo de producto<select name="product_type" x-model="product" @change="resetOptions()" required><option value="">Selecciona</option>@foreach(\App\Models\CustomRequest::PRODUCTS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label>Cantidad<input type="number" name="quantity" min="1" max="10000" required value="{{ old('quantity', 1) }}"></label>
            <template x-if="product === '3d_print'"><div class="custom-3d-fields">@include('custom-requests.three-d-fields')</div></template>
            <template x-if="product === 'mug'"><label>Variante<select name="variant" x-model="variant" required>@foreach(config('custom_orders.mug_variants') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label></template>
            <template x-if="isShirt"><label>Talla<select name="size" x-model="size" required><option value="">Selecciona</option>@foreach(['S','M','L','XL','XXL'] as $size)<option>{{ $size }}</option>@endforeach</select></label></template>
            <template x-if="isShirt"><label>Ubicación del diseño<select name="print_position" x-model="position" required><option value="">Selecciona</option>@foreach(\App\Models\CustomRequest::PRINT_POSITIONS as $position)<option>{{ $position }}</option>@endforeach</select></label></template>
            <template x-if="product === 'badges'"><div><p>Producto: Chapita personalizada</p><dl aria-label="Medida estándar"><dt>Medida</dt><dd>Estándar</dd></dl></div></template>
            <template x-if="product === 'stickers'"><label>Tamaño aproximado<input x-model="previewDimensions" name="dimensions" maxlength="255" required placeholder="Ej.: 5 × 5 cm" value="{{ old('dimensions') }}"></label></template>
            <template x-if="product === 'patch'"><div class="custom-3d-fields">@include('custom-requests.patch-fields')</div></template>
            @auth
            <label>Nombre de tu cuenta<input readonly autocomplete="name" value="{{ auth()->user()->name }}"></label>
            <label>Correo de tu cuenta<input readonly type="email" autocomplete="email" value="{{ auth()->user()->email }}"></label>
            @endauth
            <label>Teléfono (opcional)<input name="customer_phone" type="tel" autocomplete="tel" maxlength="40" value="{{ old('customer_phone') }}"></label>
        </div>
        <label><span x-text="product === '3d_print' ? 'Observaciones' : 'Instrucciones / detalles (opcional)'">Instrucciones / detalles (opcional)</span><textarea name="instructions" rows="5" maxlength="10000" placeholder="Colores, ubicación, observaciones y todo lo que debamos saber">{{ old('instructions') }}</textarea></label>
            <div class="custom-upload">
                <label for="attachments" x-text="product === '3d_print' ? 'Modelos 3D y referencias' : 'Diseños y referencias'">Diseños y referencias</label>
                <p>JPG, JPEG, PNG, WebP y PDF: hasta {{ config('custom_orders.reference_max_kb') / 1024 }} MB por archivo. Máximo {{ config('custom_orders.max_files') }} archivos.</p>
                <p x-show="product === '3d_print'">También STL, OBJ y 3MF: hasta {{ config('custom_orders.model_max_kb') / 1024 }} MB por modelo. Puedes enviar referencias si todavía no tienes un modelo 3D.</p>
                <p>Todos los archivos son privados. Solo tú y nuestro equipo administrador podrán acceder a ellos.</p>
                <input id="attachments" x-ref="attachments" name="attachments[]" type="file" multiple required :accept="product === '3d_print' ? '.jpg,.jpeg,.png,.webp,.pdf,.stl,.obj,.3mf' : '.jpg,.jpeg,.png,.webp,.pdf'" @change="previewFiles($event, {{ config('custom_orders.reference_max_kb') }}, {{ config('custom_orders.model_max_kb') }}, {{ config('custom_orders.max_files') }})">
                <p x-show="modelError" x-text="modelError" role="alert"></p>
                <div class="custom-attachment-list"><template x-for="(attachment, index) in modelAttachments" :key="index"><div class="custom-attachment">
                    <img x-show="attachment.url" :src="attachment.url" alt="Vista previa de la referencia seleccionada" class="custom-preview">
                    <span x-show="attachment.pdf" class="custom-file-icon" aria-label="Archivo PDF">PDF</span>
                    <p x-show="attachment.model">Modelo 3D seleccionado</p><strong x-text="attachment.name"></strong><p x-text="attachment.size"></p>
                    <a :href="attachment.downloadUrl" :download="attachment.name">Descargar</a>
                </div></template></div>
                <button x-show="modelAttachments.length" type="button" class="shop-button green" @click="$refs.attachments.click()">Cambiar archivos</button>
            </div>
        @include('custom-requests.preview-editor')
        @include('custom-requests.design-assistance')
        @auth<button class="shop-button" type="submit">Enviar solicitud de cotización →</button>@else<a class="shop-button" href="{{ route('custom-requests.continue') }}">Iniciar sesión para enviar</a>@endauth
        <p class="custom-disclaimer">El envío es una solicitud de cotización. Revisaremos la viabilidad de tu diseño antes de confirmar el trabajo.</p>
    </form>
</div>
@endsection
