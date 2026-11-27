<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>
        {{ $kind === 'verify_email'
            ? '¡SUUUUPER! Activa tu cuenta'
            : ($kind === 'password_reset'
                ? '¡Franky al rescate! Recupera tu cuenta'
                : '¡Noticias de tu tesoro!') }}
    </title>
</head>

<body style="margin:0;background:#f2f5ef;color:#20251f;font-family:Arial,sans-serif">

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f5ef;padding:24px 8px">

        <tr>
            <td align="center">

                <table role="presentation" width="600" cellspacing="0" cellpadding="0"
                    style="width:100%;max-width:600px;background:#fff;border-radius:12px;overflow:hidden">

                    <!-- ENCABEZADO -->
                    <tr>
                        <td style="background:#087b39;padding:28px 24px;text-align:center;color:#fff">

                            <span role="img" aria-label="Shop D. Nakama"
                                style="display:block;width:113px;height:113px;margin:auto;background-image:url('{{ asset('images/shop-reference.png') }}');background-size:961.06% 1281.42%;background-position:3.08% 3.14%">
                            </span>

                            <p
                                style="margin:16px 0 4px;font-size:13px;font-weight:bold;letter-spacing:3px;color:#d4f5dc">
                                MENSAJE DE LA TRIPULACIÓN
                            </p>

                            <p style="margin:8px 0;font-size:32px;font-weight:900;letter-spacing:1px">
                                ¡SUUUUUUPER!
                            </p>

                            <p style="margin:8px 0 0;font-size:24px">
                                🤖 🔧 ⭐
                            </p>

                        </td>
                    </tr>

                    <!-- SEPARADOR -->
                    <tr>
                        <td style="height:5px;background:#efbd40;font-size:0;line-height:0">
                            &nbsp;
                        </td>
                    </tr>

                    <!-- CONTENIDO -->
                    <tr>
                        <td style="padding:32px 28px;line-height:1.7;font-size:15px">

                            <p style="font-size:19px;font-weight:bold;margin-top:0">
                                ¡¡HEY,
                                {{ filled($data['name'] ?? null) ? $data['name'] : 'NAKAMA' }}!!
                            </p>

                            @switch($kind)
                                @case('verify_email')
                                    <h1 style="font-size:26px;line-height:1.3;color:#087b39;margin:20px 0">
                                        ¡UN NUEVO NAKAMA A BORDO! 🏴‍☠️
                                    </h1>

                                    <p>
                                        ¡OOOOOOH! ¿¡QUÉ TENEMOS AQUÍ!?
                                    </p>

                                    <p>
                                        ¡Parece que un nuevo integrante está a punto de unirse
                                        a nuestra increíble tripulación!
                                    </p>

                                    <p style="font-size:19px;font-weight:bold;color:#087b39">
                                        ¡¡Y ESO ES ABSOLUTAMENTE SUUUUPER!! 🔥
                                    </p>

                                    <p>
                                        Pero, ¡ALTO AHÍ, NAKAMA!
                                        Antes de zarpar hacia un océano repleto de mangas,
                                        figuras y tesoros legendarios, tenemos que comprobar
                                        que este correo realmente te pertenece.
                                    </p>

                                    <p style="font-weight:bold;text-align:center;margin:28px 0 12px">
                                        ⚙️ ¡ES HORA DE ACTIVAR LOS MOTORES!
                                    </p>

                                    <!-- BOTÓN DE VERIFICACIÓN -->
                                    <table role="presentation" cellspacing="0" cellpadding="0" align="center"
                                        style="margin:20px auto 28px">
                                        <tr>
                                            <td align="center" bgcolor="#087b39"
                                                style="border-radius:8px;border-bottom:4px solid #055525">

                                                <a href="{{ $data['url'] }}"
                                                    style="display:inline-block;background:#087b39;color:#fff;text-decoration:none;padding:16px 24px;border-radius:8px;font-size:16px;font-weight:bold">
                                                    🚀 ¡ACTIVAR MI CUENTA!
                                                </a>

                                            </td>
                                        </tr>
                                    </table>

                                    <!-- AVISO DE VENCIMIENTO -->
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
                                        style="background:#fff8e5;border-left:4px solid #efbd40;border-radius:6px">
                                        <tr>
                                            <td style="padding:16px">

                                                <p style="margin:0 0 6px;font-weight:bold;color:#805600">
                                                    ⏰ ¡OJO, NAKAMA!
                                                </p>

                                                <p style="margin:0;font-size:14px;color:#604c20">
                                                    Este enlace estará disponible durante
                                                    <strong>{{ (int) config('auth.verification.expire', 60) }} minutos.</strong>
                                                    ¡Así que no te quedes mirando el horizonte!
                                                </p>

                                            </td>
                                        </tr>
                                    </table>

                                    <p style="margin-top:24px">
                                        ¡Prepárate para descubrir tesoros increíbles,
                                        vivir nuevas aventuras y llevar tu colección
                                        al SIGUIENTE NIVEL!
                                    </p>

                                    <p style="font-size:20px;color:#087b39;font-weight:bold;text-align:center">
                                        ¡¡BIENVENIDO A LA TRIPULACIÓN!!
                                    </p>

                                    <p style="font-size:12px;color:#596257">
                                        ¿No fuiste tú quien se registró?
                                        Puedes ignorar este mensaje tranquilamente.
                                    </p>
                                @break

                                @case('password_reset')
                                    <h1 style="font-size:26px;line-height:1.3;color:#087b39;margin:20px 0">
                                        ¡FRANKY AL RESCATE! 🔧
                                    </h1>

                                    <p style="font-size:19px;font-weight:bold">
                                        ¡¡SUUUUPER EMERGENCIA, NAKAMA!! 🚨
                                    </p>

                                    <p>
                                        ¡Hemos recibido una solicitud para cambiar
                                        la contraseña de tu cuenta!
                                    </p>

                                    <p>
                                        ¿Perdiste el rumbo? ¿Olvidaste tu contraseña?
                                    </p>

                                    <p style="font-size:19px;font-weight:bold;color:#087b39">
                                        ¡¡NOOOOO HAY PROBLEMA!!
                                    </p>

                                    <p>
                                        Hasta los mejores navegantes pierden el rumbo
                                        de vez en cuando. Por suerte, tenemos todas
                                        las herramientas necesarias para poner tu
                                        barco nuevamente en marcha.
                                    </p>

                                    <p style="font-weight:bold;text-align:center;margin:28px 0 12px">
                                        ⚙️ ¡MANOS A LA OBRA!
                                    </p>

                                    <!-- BOTÓN DE RECUPERACIÓN -->
                                    <table role="presentation" cellspacing="0" cellpadding="0" align="center"
                                        style="margin:20px auto 28px">
                                        <tr>
                                            <td align="center" bgcolor="#087b39"
                                                style="border-radius:8px;border-bottom:4px solid #055525">

                                                <a href="{{ $data['url'] }}"
                                                    style="display:inline-block;background:#087b39;color:#fff;text-decoration:none;padding:16px 24px;border-radius:8px;font-size:16px;font-weight:bold">
                                                    🔧 ¡RECUPERAR MI CONTRASEÑA!
                                                </a>

                                            </td>
                                        </tr>
                                    </table>

                                    <!-- AVISO DE SEGURIDAD -->
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
                                        style="background:#fff8e5;border-left:4px solid #efbd40;border-radius:6px">
                                        <tr>
                                            <td style="padding:16px">

                                                <p style="margin:0 0 6px;font-weight:bold;color:#805600">
                                                    🔒 ¡ATENCIÓN, NAKAMA!
                                                </p>

                                                <p style="margin:0;font-size:14px;color:#604c20">
                                                    Si no solicitaste este cambio, simplemente
                                                    ignora este mensaje. No necesitas realizar
                                                    ninguna otra acción.
                                                </p>

                                            </td>
                                        </tr>
                                    </table>

                                    <p style="margin-top:24px">
                                        ¡Tu seguridad es tan importante como
                                        proteger el Thousand Sunny!
                                    </p>

                                    <p style="font-size:20px;color:#087b39;font-weight:bold;text-align:center">
                                        ¡¡SIGUE NAVEGANDO CON ESTILO!!
                                    </p>
                                @break

                                @default
                                    <h1 style="font-size:26px;line-height:1.3;color:#087b39;margin:20px 0">
                                        ¡NOTICIAS DESDE EL BARCO! 🚢
                                    </h1>

                                    <p>
                                        ¡HEY, NAKAMA! Tenemos una nueva
                                        actualización sobre tu tesoro.
                                    </p>

                                    <h2 style="font-size:21px;color:#087b39">
                                        {{ $data['heading'] ?? 'Actualización de tu pedido' }}
                                    </h2>

                                    <p>
                                        {{ $data['message'] ?? 'Tenemos una actualización sobre tu pedido.' }}
                                    </p>

                                    @if (!empty($data['number']))
                                        <!-- INFORMACIÓN DEL PEDIDO -->
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
                                            style="background:#f2f5ef;border:1px solid #dce5d9;border-radius:8px;margin:24px 0">

                                            <tr>
                                                <td colspan="2"
                                                    style="background:#e1eee2;padding:14px 16px;color:#087b39;font-size:16px;font-weight:bold;border-radius:8px 8px 0 0">
                                                    📦 INFORMACIÓN DE TU TESORO
                                                </td>
                                            </tr>

                                            <tr>
                                                <td style="padding:14px 16px;border-bottom:1px solid #dce5d9">
                                                    🏴‍☠️ Pedido
                                                </td>

                                                <td align="right"
                                                    style="padding:14px 16px;border-bottom:1px solid #dce5d9;font-weight:bold">
                                                    {{ $data['number'] }}
                                                </td>
                                            </tr>

                                            <tr>
                                                <td style="padding:14px 16px;border-bottom:1px solid #dce5d9">
                                                    🚢 Estado
                                                </td>

                                                <td align="right"
                                                    style="padding:14px 16px;border-bottom:1px solid #dce5d9;font-weight:bold">
                                                    {{ $data['status'] ?? 'En actualización' }}
                                                </td>
                                            </tr>

                                            <tr>
                                                <td style="padding:14px 16px">
                                                    💰 Pago
                                                </td>

                                                <td align="right" style="padding:14px 16px;font-weight:bold">
                                                    {{ $data['payment_status'] ?? 'En actualización' }}
                                                </td>
                                            </tr>

                                        </table>
                                    @endif

                                    <p>
                                        ¡Mantente atento a las novedades
                                        de nuestra tripulación!
                                    </p>

                                    <p style="font-size:20px;color:#087b39;font-weight:bold;text-align:center">
                                        ¡¡GRACIAS POR NAVEGAR CON NOSOTROS!!
                                    </p>

                            @endswitch

                            <!-- DESPEDIDA -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
                                style="margin-top:32px;border-top:1px solid #e1e7df">

                                <tr>
                                    <td align="center" style="padding:24px 0 8px">

                                        <p style="font-size:25px;font-weight:900;color:#087b39;margin:0 0 10px">
                                            ¡SUUUUUUUUPER! ⭐
                                        </p>

                                        <p style="font-size:14px;color:#596257;margin:0">
                                            Con toda la energía de la tripulación,
                                        </p>

                                        <p style="font-size:16px;font-weight:bold;margin:6px 0">
                                            ⚓ Shop D. Nakama
                                        </p>

                                    </td>
                                </tr>

                            </table>

                        </td>
                    </tr>

                    <!-- PIE DEL CORREO -->
                    <tr>
                        <td style="background:#064c29;padding:24px 16px;text-align:center;color:#fff">

                            <p style="font-size:16px;font-weight:bold;margin:0 0 8px">
                                SHOP D. NAKAMA
                            </p>

                            <p style="font-size:12px;color:#c0e0ce;margin:0 0 16px">
                                Anime · Manga · Coleccionismo · Cultura Geek
                            </p>

                            <p style="font-size:13px;font-weight:bold;margin:0 0 16px">
                                Cada nakama tiene un tesoro por descubrir.
                            </p>

                            <a href="{{ config('app.url') }}"
                                style="font-size:13px;color:#fff;text-decoration:underline">
                                ¡Volver a la tienda!
                            </a>

                            <p style="font-size:11px;color:#c0e0ce;margin:20px 0 0">
                                © {{ date('Y') }} Shop D. Nakama.
                                Todos los derechos reservados.
                            </p>

                        </td>
                    </tr>

                </table>

            </td>
        </tr>

    </table>

</body>

</html>
