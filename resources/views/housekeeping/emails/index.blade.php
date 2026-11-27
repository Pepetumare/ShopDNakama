@extends('housekeeping.layout')
@section('title', 'Correos transaccionales')
@section('content')
<h1>Correos transaccionales</h1>
<p>Consulta avisos enviados, pendientes y fallidos. Reintentar no repite acciones del pedido.</p>
<div class="hk-table-wrap"><table class="hk-table"><thead><tr><th>Fecha</th><th>Tipo</th><th>Destinatario</th><th>Pedido</th><th>Estado</th><th>Intentos</th><th>Incidencia</th><th>Acción</th></tr></thead><tbody>
@forelse($notifications as $notice)
<tr><td>{{ $notice->created_at?->format('d-m-Y H:i') }}</td><td>{{ str_replace('_', ' ', $notice->type) }}</td><td>{{ \Illuminate\Support\Str::mask($notice->recipient, '*', 2, max(0, strpos($notice->recipient, '@') - 2)) }}</td><td>{{ $notice->order?->number ?? '—' }}</td><td>{{ $notice->status }}</td><td>{{ $notice->attempts }}</td><td>{{ $notice->last_error ? \Illuminate\Support\Str::limit($notice->last_error, 120) : '—' }}@if($notice->events->isNotEmpty())<details><summary>Eventos ({{ $notice->events->count() }})</summary><ul>@foreach($notice->events as $event)<li>{{ $event->created_at?->format('d-m-Y H:i:s') }} · {{ $event->event }}@if($event->message) — {{ $event->message }}@endif</li>@endforeach</ul></details>@endif</td><td>@if($notice->status === 'failed')<form method="post" action="{{ route('housekeeping.emails.retry', $notice) }}">@csrf<button class="hk-button" type="submit">Reintentar correo</button></form>@endif</td></tr>
@empty<tr><td colspan="8">Aún no hay notificaciones registradas.</td></tr>@endforelse
</tbody></table></div>
{{ $notifications->links() }}
@endsection
