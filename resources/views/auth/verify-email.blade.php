<x-guest-layout>
    <x-slot name="title">Verifica tu correo</x-slot>
    <x-slot name="subtitle">Revisa tu bandeja de entrada y abre el enlace de verificación que te enviamos. Si no lo encuentras, revisa la carpeta de spam o solicita uno nuevo.</x-slot>
    <x-auth.form :action="route('verification.send')">
        <x-auth.submit>Reenviar correo de verificación</x-auth.submit>
    </x-auth.form>
    <x-auth.form :action="route('logout')" class="auth-switch">
        <button type="submit" class="auth-text-button">Cerrar sesión</button>
    </x-auth.form>
</x-guest-layout>