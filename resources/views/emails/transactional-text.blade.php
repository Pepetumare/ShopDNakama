
@if($kind === 'verify_email')
¡¡¡SUUUUUUUUUUUPER!!! 🤖🔥⭐

¡¡HEY, {{ $data['name'] ?? 'futuro nakama' }}!!

¿QUÉ TENEMOS AQUÍÍÍ?!! 🔧💥

¡Parece que un nuevo nakama está a punto de unirse a nuestra tripulación!

¡Y ESO ES ABSOLUTAMENTE SUUUUPER! 🚀

Pero, ¡ALTO AHÍ, HERMANO! ✋

Antes de zarpar hacia un océano lleno de mangas, figuras y tesoros legendarios, tenemos que comprobar que este correo realmente te pertenece.

¡ES HORA DE ACTIVAR LOS MOTORES! ⚙️🔥

👇 ¡PULSA ESTE ENLACE Y CONFIRMA TU CORREO!

{{ $data['url'] }}

⏰ ¡OJO, NAKAMA!
Este enlace solo estará disponible durante {{ (int) config('auth.verification.expire', 60) }} minutos.

¡Así que no te quedes mirando el horizonte!

¿No fuiste tú quien se registró? ¡Entonces ignora este mensaje y sigue navegando tranquilamente!

━━━━━━━━━━━━━━━━━━━━

🏴‍☠️ ¡LA TRIPULACIÓN TE ESPERA!

¡Prepárate para encontrar tesoros increíbles, vivir aventuras y llevar tu colección al SIGUIENTE NIVEL!

¡¡¡BIENVENIDO A SHOP D. NAKAMA!!!

¡¡¡SUUUUUUUUUUUUUPER!!! 🤖🔥

Con toda la energía de la tripulación,
⚓ Shop D. Nakama

@elseif($kind === 'password_reset')
¡¡¡SUUUUUUPER EMERGENCIA, NAKAMA!!! 🚨🤖

¡HEY, {{ $data['name'] ?? 'nakama' }}!

¡FRANKY AL RESCATE! 🔧💥

¿Olvidaste tu contraseña?

¡¡NOOOOO HAY PROBLEMA!!

¡Hasta los mejores navegantes pierden el rumbo de vez en cuando!

¡Pero tranquilo, porque tenemos las herramientas necesarias para poner tu barco nuevamente en marcha! ⚙️🔥

👇 ¡PULSA AQUÍ PARA CREAR UNA NUEVA CONTRASEÑA!

{{ $data['url'] }}

🔒 ¡ATENCIÓN, NAKAMA!

Si no solicitaste este cambio, simplemente ignora este mensaje. ¡No necesitas hacer nada más!

¡Tu seguridad es tan importante como proteger el Thousand Sunny!

¡¡¡SIGUE NAVEGANDO CON ESTILO!!!

¡¡¡SUUUUUUUUPER!!! ⭐🤖

⚓ Shop D. Nakama

@else
¡¡¡SUUUUUUPER NOTICIAS DESDE EL BARCO!!! 🚢🔥

¡HEY, {{ $data['name'] ?? 'nakama' }}!

¡TENEMOS NOVEDADES SOBRE TU TESORO! 📦💥

{{ $data['heading'] ?? 'Actualización de tu pedido' }}

{{ $data['message'] ?? 'Tenemos una actualización sobre tu pedido.' }}

@if(!empty($data['number']))
━━━━━━━━━━━━━━━━━━━━

📦 ¡DATOS DE TU TESORO!

🏴‍☠️ Pedido: {{ $data['number'] }}

🚢 Estado: {{ $data['status'] ?? 'En actualización' }}

💰 Pago: {{ $data['payment_status'] ?? 'En actualización' }}

━━━━━━━━━━━━━━━━━━━━
@endif

¡Nuestra tripulación está trabajando a toda máquina para que tu aventura sea INCREÍBLE! 🔧⚙️

¡Mantente atento a las próximas novedades!

¡¡¡MUCHAS GRACIAS POR CONFIAR EN NOSOTROS, NAKAMA!!!

¡¡¡SUUUUUUUUUUUUPER!!! 🤖🔥⭐

Con toda la energía de la tripulación,
⚓ Shop D. Nakama
@endif
