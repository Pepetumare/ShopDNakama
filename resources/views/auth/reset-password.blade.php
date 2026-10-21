<x-guest-layout>
    <x-slot name="title">Nueva contraseña</x-slot>
    <x-slot name="subtitle">Elige una nueva contraseña para volver a tu aventura.</x-slot>
    <x-auth.form :action="route('password.store')">
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-auth.field name="email" label="Correo electrónico" type="email" :value="old('email', $request->email)" autocomplete="username" autofocus />
        <x-auth.field name="password" label="Nueva contraseña" type="password" autocomplete="new-password" />
        <x-auth.field name="password_confirmation" label="Confirmar contraseña" type="password" autocomplete="new-password" />
        <x-auth.submit>Actualizar contraseña</x-auth.submit>
    </x-auth.form>
    <p class="auth-switch"><a href="{{ route('login') }}">Volver a iniciar sesión</a></p>
</x-guest-layout>