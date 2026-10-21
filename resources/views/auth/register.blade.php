<x-guest-layout>
    <x-slot name="title">Únete a Shop D. Nakama</x-slot>
    <x-slot name="subtitle">Crea tu cuenta y forma parte de la tripulación.</x-slot>
    <x-auth.form :action="route('register')">
        <x-auth.field name="name" label="Nombre" autocomplete="name" autofocus />
        <x-auth.field name="email" label="Correo electrónico" type="email" autocomplete="username" placeholder="tu@correo.cl" />
        <x-auth.field name="password" label="Contraseña" type="password" autocomplete="new-password" />
        <x-auth.field name="password_confirmation" label="Confirmar contraseña" type="password" autocomplete="new-password" />
        <x-auth.submit>Crear cuenta</x-auth.submit>
    </x-auth.form>
    <p class="auth-switch">¿Ya tienes cuenta? <a href="{{ route('login') }}">Iniciar sesión</a></p>
</x-guest-layout>