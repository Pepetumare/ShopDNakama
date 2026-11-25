# AUD-002 · Recuperación de reservas vencidas

En la base `965ea77`, una reserva vencida seguía restando saldo disponible. El scheduler aceptaba la ausencia de preferencia e intento como prueba de no publicación. El comando manual liberaba cualquier fila vencida al recibir `--verified` y un texto. La prueba original de checkout reproducía el efecto con un pago pendiente: dos unidades retenidas volvían a estar disponibles. Por otra parte, el webhook trataba una aprobación puntual recibida después del vencimiento como incidencia incluso cuando las unidades seguían retenidas.

## Decisiones

| Evidencia del pedido | Transición de inventario |
| --- | --- |
| Reserva activa | Mantener la retención hasta consumo, vencimiento o fallo de preenvío acreditado. |
| Intento de catálogo `not_sent`, sin evidencia contradictoria | Liberar. Ese estado se escribe para el intento vinculado solo al capturar `PreferenceNotSent` durante creación, cuando el transporte todavía no ha enviado el POST. La liberación inmediata y el cambio de estado comparten transacción. El scheduler puede retomar una reserva vencida que conserve esa evidencia. |
| Intento ausente, histórico `failed`, checkout enviando o incierto | Mantener. Un hueco en los datos históricos o un timeout pueden ocultar una preferencia. |
| Preferencia publicada sin pago conocido | Mantener incluso si el enlace expiró, el barrido terminó o la búsqueda devolvió cero pagos. |
| Pago pendiente, en proceso u otro ID pendiente de detalle | Mantener y seguir conciliando con AUD-004. |
| Pago aprobado durante la reserva | Consumir una vez. Tras el vencimiento se requiere fecha de aprobación del detalle verificado dentro de la ventana original, coincidencia explícita de la preferencia, filas aún retenidas y saldo físico que cubra las reservas. El límite superior es exclusivo. |
| Pago aprobado después del vencimiento, fecha insuficiente o unidades liberadas | Registrar el pago, mantener la incidencia y bloquear preparación y despacho. Se conserva la política existente de revisión humana para estas aprobaciones. |
| Pago rechazado o cancelado | Mantener una reserva publicada. Un resultado terminal individual permite que exista un segundo pago. |
| Reembolso o contracargo | Seguir el flujo de reversión existente. La reposición requiere una venta descontada y una operación de inventario explícita e idempotente. |
| Error HTTP, revisión incompleta o incidencia financiera abierta | Mantener. Investigar la incidencia y dejar que los reintentos durables obtengan el detalle. |
| Pago desconocido descubierto después | Verificar referencia, importe, moneda y preferencia mediante la conciliación existente. Las unidades liberadas nunca se reclaman automáticamente. |
| Personalizado con reserva | Proteger frente a liberación por señales del checkout de catálogo. Continúa el seguimiento financiero de AUD-001/AUD-004. |
| Ya liberada o consumida | Las repeticiones conservan el estado, el motivo original y el libro de movimientos. |

## Operación

1. Ejecutar `php artisan stock:held-reservations` después del mantenimiento de vencimientos. Cada línea identifica ID, número de pedido y todas las causas observadas. Pueden coexistir un pago pendiente, una incidencia abierta, un checkout incierto, una preferencia posiblemente publicada, un fallo de búsqueda y un detalle aún sin verificar. Para continuar tras los primeros cien pedidos usar `--after=ID`.
2. Revisar en Housekeeping el pedido, pagos, incidencias y eventos. Correlacionar los IDs con evidencia del proveedor. El inventario comprometido sigue descontándose del saldo disponible mientras el caso esté abierto.
3. En un pedido elegible, `php artisan stock:release-reservation NUMERO --verified --reason="Referencia de revisión"` vuelve a comprobar la evidencia bajo bloqueo. Guarda fecha, intento y motivo completo en las filas liberadas. La interfaz admite 255 caracteres de motivo. El servicio comprueba que el registro final cabe antes de escribir y conserva la evidencia original en los reintentos. Liberar devuelve disponibilidad sin incrementar el stock físico.
4. Si hubo publicación posible, obtener evidencia de cierre de **toda la preferencia** y de sus intentos, incluidas operaciones aún en proceso. Conservar la referencia en la revisión administrativa y escalar al responsable de pagos e inventario. El contrato integrado de búsqueda y detalle no proporciona aquí una garantía exhaustiva e irreversible. La reserva sigue protegida. Un plazo de gracia o la firma de un operador trasladarían a la tienda el riesgo de un cobro posterior y requieren una decisión comercial independiente.
5. Si hay cobro real sin asignación, inmovilizar preparación y despacho. Revisar disponibilidad física, ventas y reservas antes de decidir con el responsable una asignación o una devolución. No usar ajustes de stock para sortear el bloqueo ni disparar reembolsos desde este mantenimiento.

## Transacciones y compatibilidad

La recuperación toma el bloqueo del intento y después el del pedido, en el orden del checkout. La conciliación y el webhook serializan la aplicación sobre el mismo pedido. Los productos se bloquean en orden estable antes de consumir unidades. Una caída antes del commit revierte el cambio de estado y el movimiento. Al repetir, las filas terminales y las claves de idempotencia impiden una segunda aplicación.

Antes de habilitar la versión corregida se requiere aplicar la migración que amplía `stock_reservations.release_reason` de 255 a 320 caracteres. Esto permite conservar un motivo previo de 255 caracteres junto con la identidad del intento. Al revertir, detener los escritores de reservas: si existen registros de más de 255 caracteres, la migración interrumpe el rollback para conservarlos íntegros.

La asignación de cada SKU comprueba bajo bloqueo que el saldo físico posterior a la venta respalde las demás reservas activas y vencidas. Se validan todas las líneas antes del primer descuento. Para acreditar una aprobación durante la vigencia se valida calendario, reloj y offset explícito, y se compara el instante con el límite superior exclusivo. Una fecha suministrada inválida conserva el pago aprobado y deja una incidencia que bloquea la preparación.

Las filas antiguas sin evidencia positiva quedan protegidas. El registro de IDs de AUD-004 ayuda a encontrar pagos posteriores; un barrido completo no autoriza liberación. La demora de indexación y la ausencia de una prueba externa de cierre pueden mantener reservas publicadas indefinidamente.

## Ventanas absolutas y fechas de pago · Corrección final del 25 de noviembre de 2026

Las conexiones Laravel `mysql` y `mariadb` inicializan la sesión en `+00:00`, también al reconectar. La escritura de la ventana y la conciliación verifican la zona de la conexión de escritura dentro de la transacción. Si el llamador la cambió, se usa UTC durante esa operación y se restaura la zona anterior. Para las reservas acreditadas se lee `UNIX_TIMESTAMP(created_at)` y `UNIX_TIMESTAMP(expires_at)` directamente de las columnas. Esa lectura sirve al checkout, a las fechas enviadas al proveedor y al consumo bajo bloqueo. La ventana incluye la creación y excluye el vencimiento. La comparación usa la precisión del dato del proveedor hasta microsegundos, aunque el historial de transacciones solo admite milisegundos.

Las fechas de aprobación y actualización del proveedor requieren calendario y offset válidos. En el esquema MariaDB 10.11, el primer milisegundo almacenable es `1970-01-01T00:00:00.001Z` y el último es `2038-01-19T03:14:07.999Z`. Cuando el dato suministrado es inválido o queda fuera del rango, el evento conserva el valor recibido, el campo y la causa en `provider_date_evidence`. Housekeeping muestra esos datos en el historial. La transacción conserva la identidad del pago y sus campos financieros. Un valor inutilizable deja el campo temporal nulo si no había un valor válido anterior. La aprobación se registra y la asignación queda bloqueada hasta revisión. La ausencia de fecha en una respuesta posterior mantiene una incidencia temporal abierta. Un reembolso o contracargo conserva su precedencia y requiere el procedimiento existente para cualquier reposición.

**Antes de activar el código**, respaldar y ensayar en una copia del hosting, detener los escritores, aplicar primero la migración de `release_reason` y después `2026_11_25_000001_record_financial_time_evidence`. Reiniciar los workers con la configuración nueva y verificar `@@session.time_zone` en HTTP, scheduler y CLI. Revisar los registros financieros y leases todavía activos si sus escritores históricos usaban una zona distinta de UTC. Evitar una conversión masiva de los TIMESTAMP.

La migración conserva las filas históricas. La columna `absolute_window` acredita que el escritor conocía los instantes de la reserva. Las reservas anteriores mantienen el valor falso. Siguen reteniendo unidades y una aprobación que las necesite queda pagada con la incidencia `unverified_reservation_window`. La recuperación automática de una preferencia que dependa de esa ventana también se detiene. Para resolver, contrastar la fecha original de la preferencia, registros de creación, zona efectiva de la antigua conexión y unidades disponibles. Si falta evidencia, mantener la reserva protegida y documentar la revisión en el pedido. No marcar en bloque las filas antiguas.

El rollback requiere escritores detenidos y solo se permite mientras no se hayan registrado ventanas acreditadas ni evidencia de fechas. Después, conservar el esquema ampliado y utilizar una corrección compatible. El DDL puede reintentarse después de una aplicación parcial.

La clase permanente `FinancialTimestampTest` cubre los dos defectos, zonas SQL, reconexión de ambos conectores, fechas límite, reversión, reintentos y pedidos con dos SKU. Ejecutar sus casos de sesiones en la misma MariaDB aislada que las carreras. La tabla siguiente corresponde a la validación anterior a esta corrección.

## Validación local

En el commit base, el caso `test_pending_and_expired_reservations_hold_stock_until_reconciliation` pasó con 19 aserciones y reprodujo la liberación insegura después de un pago pendiente. En AUD-002 ese caso exige que el comando falle y que el saldo siga retenido. También cambió la expectativa del caso de reanudación de un checkout publicado y vencido.

| Ejecución sobre AUD-002 | Resultado |
| --- | --- |
| PHPUnit completo, SQLite en memoria | 406 casos, 3.946 aserciones, 30 omitidos por requerir MariaDB, cero fallos y errores |
| MariaDB/InnoDB aislado, casos permanentes | Los 30 omitidos se ejecutaron con 460 aserciones, todos correctos |
| MariaDB, regresiones independientes adicionales de AUD-004 | 3 casos, 43 aserciones, todos correctos |
| Reproducciones independientes originales de AUD004-IND-01 | 2 casos correctos |
| Migraciones sobre esquema desechable | Aplicación, rollback sin registros largos y reaplicación correctos. La reversión con evidencia larga se rechazó y conservó el texto en MariaDB. |
| Cuatro hallazgos de la auditoría | Reproducidos antes del cambio. Los 17 casos originales que los contienen pasaron después en SQLite y en MariaDB, con 73 aserciones en cada motor. |
| Fronteras de fechas, stock, listado y texto de auditoría | 18 casos permanentes, 107 aserciones en MariaDB; incluidos también en la suite completa |
| Pint y comprobación de espacios del diff | Correctos |

Para reproducir, usar las [instrucciones de entorno aislado de AUD-004](aud-004-reconciliation-pagination.md#ejecutar-desde-un-checkout-independiente), sus dependencias y los assets del frontend compilados. PHPUnit completo incluye las regresiones de AUD-001, AUD-003 y AUD-004. El filtro MariaDB publicado allí incluye los dos nuevos escenarios de recuperación de AUD-002 en `PaymentReconciliationConcurrencyTest`. Los tres casos independientes adicionales y las dos reproducciones originales se ejecutaron desde copias externas.

Las carreras cubren dos compradores de la última unidad, dos procesos que intentan liberar la misma reserva elegible, liberación concurrente con una aprobación y repetición del webhook mientras el scheduler está esperando en HTTP. Se comprueba la unicidad de la venta y, en caso de liberación, la ausencia de movimientos físicos. La prueba funcional de aprobación puntual recibida tarde también verifica la reposición explícita, una sola vez, tras el reembolso.
