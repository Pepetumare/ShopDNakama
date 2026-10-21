<x-guest-layout>
    <x-slot name="title">Bienvenido, nakama</x-slot>
    <x-slot name="subtitle">Inicia sesión para ver tus pedidos, favoritos y continuar tu aventura.</x-slot>
    <x-auth.form :action="route('login')">
        <x-auth.field name="email" label="Correo electrónico" type="email" autocomplete="username" placeholder="tu@correo.cl" autofocus />
        <x-auth.field name="password" label="Contraseña" type="password" autocomplete="current-password" />
        <div class="auth-options">
            <label class="auth-remember" for="remember_me"><input id="remember_me" type="checkbox" name="remember" @checked(old('remember'))> Recordarme</label>
            <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
        </div>
        <x-auth.submit>Iniciar sesión</x-auth.submit>
    </x-auth.form>
    <p class="auth-switch">¿Aún no tienes cuenta? <a href="{{ route('register') }}">Crear cuenta</a></p>
</x-guest-layout>