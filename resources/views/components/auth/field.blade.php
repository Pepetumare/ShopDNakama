@props(['name', 'label', 'type' => 'text', 'value' => null, 'autocomplete' => null, 'autofocus' => false, 'required' => true, 'placeholder' => null])
<div class="auth-field" @if($type === 'password') x-data="{ visible: false }" @endif>
    <label for="{{ $name }}">{{ $label }}</label>
    @if($type === 'password')<div class="auth-password">@endif
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
            @if($type !== 'password') value="{{ $value ?? old($name) }}" @endif
            @if($type === 'password') :type="visible ? 'text' : 'password'" @endif
            @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if($placeholder) placeholder="{{ $placeholder }}" @endif
            @required($required) @if($autofocus) autofocus @endif
            @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
            {{ $attributes }}>
    @if($type === 'password')
        <button type="button" @click="visible = !visible" :aria-pressed="visible" aria-controls="{{ $name }}" :aria-label="(visible ? 'Ocultar: ' : 'Mostrar: ') + @js($label)" x-text="visible ? 'Ocultar' : 'Mostrar'">Mostrar</button>
        </div>
    @endif
    <x-input-error :id="$name.'-error'" class="auth-errors" :messages="$errors->get($name)" />
</div>
