<x-guest-layout>
    <x-slot name="title">Confirma tu contraseña</x-slot>
    <x-slot name="subtitle">Por seguridad, confirma tu contraseña antes de continuar.</x-slot>
    <x-auth.form :action="route('password.confirm')">
        <x-auth.field name="password" label="Contraseña" type="password" autocomplete="current-password" autofocus />
        <x-auth.submit>Confirmar</x-auth.submit>
    </x-auth.form>
</x-guest-layout>