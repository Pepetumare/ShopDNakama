<h3>Parche personalizado</h3>
<div class="custom-fields">
    @foreach(['width' => 'Ancho aproximado', 'height' => 'Alto aproximado'] as $field => $label)
    <label>{{ $label }}<input x-model="{{ $field === 'width' ? 'previewWidth' : 'previewHeight' }}" name="{{ $field }}" type="number" min="0.001" max="999999999.999" step="0.001" required value="{{ old($field) }}"></label>
    @endforeach
    <div><p>Unidad: cm</p></div>
    <label>Forma<select x-model="previewShape" name="shape" required><option value="">Selecciona</option>@foreach(\App\Models\CustomRequest::PATCH_SHAPES as $value => $label)<option value="{{ $value }}" @selected(old('shape') === $value)>{{ $label }}</option>@endforeach</select></label>
    <label>Color de borde (opcional)<input name="border_color" maxlength="100" value="{{ old('border_color') }}"></label>
</div>
