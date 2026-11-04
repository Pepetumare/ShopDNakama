@extends('account.layout', ['accountTitle' => 'Mis personalizados'])
@section('account-content')
<p><a class="shop-button green" href="{{ route('custom-requests.create') }}">Nueva solicitud</a></p>
<div class="custom-table-wrap"><table class="custom-table"><thead><tr><th>Número solicitud</th><th>Producto</th><th>Fecha</th><th>Cantidad</th><th>Estado</th><th>Precio cotizado</th><th></th></tr></thead><tbody>
@forelse($customRequests as $customRequest)
<tr><td>{{ $customRequest->request_number }}</td><td>{{ $customRequest->product_label }}</td><td>{{ $customRequest->created_at->format('d/m/Y') }}</td><td>{{ $customRequest->quantity }}</td><td>{{ $customRequest->status_label }}</td><td>@if($customRequest->quoted_price !== null)<x-store.price :amount="$customRequest->quoted_price"/>@else Por cotizar @endif</td><td><a href="{{ route('account.custom-requests.show', $customRequest) }}">Ver</a></td></tr>
@empty<tr><td colspan="7">Todavía no tienes solicitudes de personalizados.</td></tr>@endforelse
</tbody></table></div>
<x-orders.pagination :paginator="$customRequests"/>
@endsection
