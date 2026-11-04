@props(['customRequest', 'admin' => false])
@if($customRequest->design_assistance)
<section class="cp-assistance">
    <h3>{{ $admin ? 'DISEÑO ASISTIDO' : 'Diseño asistido: Sí' }}</h3>
    @if($admin)<p>Estado: Solicitado</p><p>Servicio adicional: Diseño asistido por Shop D. Nakama</p>@endif
    <p>{{ $admin ? 'Recargo:' : 'Adaptación de diseño por nuestro equipo:' }} +<x-store.price :amount="$customRequest->design_assistance_fee"/></p>
    <h4>Indicaciones del cliente</h4><p class="cp-assistance-notes">{{ $customRequest->design_assistance_notes }}</p>
    <p>Esta vista previa es referencial. Nuestro equipo realizará los ajustes indicados antes de producir el producto.</p>
</section>
@elseif($customRequest->product_type !== '3d_print')
<p>Diseño asistido: No</p>
@endif
