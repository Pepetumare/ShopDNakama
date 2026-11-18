# AUD-001: conciliación del despacho separado

La compra ficticia de Checkout Pro del 17 de noviembre de 2026 mostró una respuesta con `transaction_amount=10000`, `shipping_amount=3990` y `transaction_details.total_paid_amount=13990`. El pedido esperaba 13990 CLP. La normalización anterior descartaba el despacho y producía `amount_mismatch`.

`MercadoPagoService::getPayment()` convierte ambos componentes a centavos y devuelve su suma como importe comercial. Se rechazan componentes negativos, no numéricos, con más de dos decimales o fuera del límite soportado. Un despacho ausente/null equivale a cero; no se infiere un despacho faltante a partir del pedido. No se usa `total_paid_amount`, ni se añaden o descuentan comisiones, cupones o financiación del comprador para intentar hacer coincidir una respuesta.

La conciliación exige igualdad del total, moneda CLP y, cuando la observación incluye el componente de despacho, igualdad con el costo guardado. Las respuestas reales del adaptador incluyen siempre ese componente; los dobles internos antiguos que devuelven el importe comercial ya normalizado mantienen su contrato.

Una incidencia anterior no se elimina automáticamente. Tras una resolución administrativa auditada, la conciliación vuelve a consultar al proveedor y puede aplicar un importe válido. Una nueva discrepancia de importe/moneda reabre la incidencia incluso si estaba resuelta. Se conservan el motivo y los eventos originales. No se modifican el orden de bloqueos, las reservas ni los controles de autorización.

Las regresiones cubren catálogo y personalizados, despacho separado/gratuito/ausente, total insuficiente o excesivo, compensación incorrecta entre productos y despacho, diferencias en el total cobrado por financiación/cupones, valores malformados, repetición sin doble descuento de stock y recuperación auditada. El fixture de CustomPaymentRecoveryTest se ajusta al formato observado del proveedor, manteniendo las expectativas comerciales existentes.

Referencia de la API de pagos (no Orders): https://www.mercadopago.cl/developers/es/reference/online-payments/checkout-api-payments/create-payment/post . La evidencia reproducida corresponde al flujo de preferencias con despacho configurado en `shipments.cost`; otras modalidades no se habilitan mediante tolerancias de importe.

En staging se volvió a consultar el mismo pago ficticio y se resolvió la incidencia mediante el servicio existente, con actor administrador y nota; el pedido quedó paid/confirmed. No se hizo otro cobro ni se modificó producción. El webhook original había validado la firma pero fallado en la consulta de red. Un servidor dedicado de staging con acceso de red y la misma puerta de entrada restringida permitió dos notificaciones de QA firmadas con respuesta 200, consulta real al proveedor y conservación de una única transacción. Esas notificaciones son pruebas controladas, no nuevas entregas originadas por Mercado Pago.
