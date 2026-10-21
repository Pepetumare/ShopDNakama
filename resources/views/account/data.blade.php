@extends('account.layout', ['accountTitle' => 'Mis datos'])
@section('account-content')
<form class="account-form" method="post" action="{{ route('account.data.update') }}">
    @csrf
    @method('PATCH')
    <p>Estos datos están asociados a tu cuenta.</p>
    <label for="account-name">Nombre</label>
    <input id="account-name" name="name" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name">
    @error('name')<p class="account-error">{{ $message }}</p>@enderror
    <label for="account-email">Correo electrónico</label>
    <input id="account-email" type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255" autocomplete="email">
    @error('email')<p class="account-error">{{ $message }}</p>@enderror
    <button class="shop-button green" type="submit">Guardar datos</button>
</form>
<div class="account-form-divider"></div>
<h3>Cambiar contraseña</h3>
<form class="account-form" method="post" action="{{ route('password.update') }}">
    @csrf
    @method('PUT')
    <label for="current-password">Contraseña actual</label><input id="current-password" type="password" name="current_password" required autocomplete="current-password">
    @foreach($errors->updatePassword->get('current_password') as $message)<p class="account-error">{{ $message }}</p>@endforeach
    <label for="new-password">Nueva contraseña</label><input id="new-password" type="password" name="password" required autocomplete="new-password">
    @foreach($errors->updatePassword->get('password') as $message)<p class="account-error">{{ $message }}</p>@endforeach
    <label for="password-confirmation">Confirmar contraseña</label><input id="password-confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
    <button class="shop-button green" type="submit">Actualizar contraseña</button>
</form>
@endsection
