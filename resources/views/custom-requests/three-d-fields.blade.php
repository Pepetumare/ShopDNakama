<h3>Impresión 3D</h3>
<div class="custom-fields">
    <label>Nombre del proyecto o pieza<input name="project_name" maxlength="255" required value="{{ old('project_name') }}"></label>
    <label>Tipo de pieza<select name="piece_type" required><option value="">Selecciona</option>@foreach(\App\Models\CustomRequest::PIECE_TYPES as $value => $label)<option value="{{ $value }}" @selected(old('piece_type') === $value)>{{ $label }}</option>@endforeach</select></label>
    @foreach(['width' => 'Ancho', 'height' => 'Alto', 'depth' => 'Profundidad'] as $field => $label)
    <label>{{ $label }} aproximado<input type="number" name="{{ $field }}" required min="0.001" max="999999999.999" step="0.001" value="{{ old($field) }}"></label>
    @endforeach
    <label>Unidad<select name="dimension_unit" required>@foreach(['mm', 'cm'] as $unit)<option @selected(old('dimension_unit', 'mm') === $unit)>{{ $unit }}</option>@endforeach</select></label>
    <label>Color deseado<input name="color" maxlength="255" required value="{{ old('color') }}" placeholder="Ej.: negro mate"></label>
    <label>Material deseado (opcional)<input name="material" maxlength="255" value="{{ old('material') }}" placeholder="Ej.: PLA, PETG o a recomendar"></label>
    <label>¿La pieza debe ser funcional o decorativa?<select name="usage_type" required><option value="">Selecciona</option>@foreach(\App\Models\CustomRequest::USAGE_TYPES as $value => $label)<option value="{{ $value }}" @selected(old('usage_type') === $value)>{{ $label }}</option>@endforeach</select></label>
</div>
<label>Descripción detallada<textarea name="description" rows="5" maxlength="10000" required>{{ old('description') }}</textarea></label>
<label>Referencias<textarea name="references" rows="3" maxlength="10000" placeholder="Describe tus referencias o pega enlaces de ejemplo">{{ old('references') }}</textarea></label>
