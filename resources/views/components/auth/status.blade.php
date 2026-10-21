@props(['status'])
@php
    $message = match ($status) {
        'verification-link-sent' => 'Enviamos un nuevo enlace de verificación a tu correo electrónico.',
        'passwords.sent', __('passwords.sent') => 'Te enviamos un enlace para restablecer tu contraseña. Revisa tu correo.',
        'passwords.reset', __('passwords.reset') => 'Tu contraseña se actualizó correctamente. Ya puedes iniciar sesión.',
        default => $status,
    };
@endphp
<x-auth-session-status class="auth-status" :status="$message" role="status" aria-live="polite" />
