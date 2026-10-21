<x-guest-layout>
    <x-slot name="title">Recupera tu acceso</x-slot>
    <x-slot name="subtitle">Ingresa tu correo y te enviaremos un enlace para restablecer tu contraseña.</x-slot>
    <x-auth.form :action="route('password.email')">
        <x-auth.field name="email" label="Correo electrónico" type="email" autocomplete="email" placeholder="tu@correo.cl" autofocus />
        <x-auth.submit>Enviar enlace</x-auth.submit>
    </x-auth.form>
    <p class="auth-switch">¿Recordaste tu contraseña? <a href="{{ route('login') }}">Iniciar sesión</a></p>
</x-guest-layout>