<?php

namespace App\Services;

use App\Models\CustomRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageCustomQuote
{
    public function update(CustomRequest $customRequest, array $data): void
    {
        DB::transaction(function () use ($customRequest, $data) {
            $quote = CustomRequest::whereKey($customRequest->id)->lockForUpdate()->firstOrFail();
            $order = $quote->order()->lockForUpdate()->first();
            if (array_key_exists('base_quoted_price', $data)) {
                $base = $data['base_quoted_price'];
                $finalCents = $base === null ? null : (int) round((float) $base * 100) + $quote->design_assistance_fee * 100;
                if ($finalCents !== null && $finalCents > 999999999999) {
                    throw ValidationException::withMessages(['base_quoted_price' => 'El total con diseño asistido supera el monto permitido.']);
                }
                $data['quoted_price'] = $finalCents === null ? null : $finalCents / 100;
            } elseif (array_key_exists('quoted_price', $data)) {
                if ($quote->design_assistance) {
                    throw ValidationException::withMessages(['base_quoted_price' => 'Ingresa el precio base; el diseño asistido se suma automáticamente.']);
                }
                $data['base_quoted_price'] = $data['quoted_price'];
            }
            $price = array_key_exists('quoted_price', $data) ? $data['quoted_price'] : $quote->quoted_price;
            $changed = ($price === null) !== ($quote->quoted_price === null)
                || (int) round((float) $price * 100) !== (int) round((float) $quote->quoted_price * 100);
            if ($order && $changed) {
                throw ValidationException::withMessages(['quoted_price' => 'La cotización ya fue aceptada y su monto no puede modificarse.']);
            }
            if ($price !== null && $changed) {
                $data['status'] = 'quoted';
            }
            if ($data['status'] === 'quoted' && (float) $price <= 0) {
                throw ValidationException::withMessages(['quoted_price' => 'Ingresa una cotización mayor a cero.']);
            }
            $transitions = [
                'pending' => ['pending', 'reviewing', 'quoted', 'rejected', 'cancelled'],
                'reviewing' => ['reviewing', 'quoted', 'rejected', 'cancelled'],
                'quoted' => ['quoted', 'rejected', 'cancelled'],
                'approved' => ['approved', 'in_production', 'rejected'],
                'in_production' => ['in_production', 'ready', 'rejected'],
                'ready' => ['ready', 'completed', 'rejected'],
                'completed' => ['completed'], 'rejected' => ['rejected'], 'cancelled' => ['cancelled'],
            ];
            if (! in_array($data['status'], $transitions[$quote->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'El estado solicitado no corresponde al flujo actual de la solicitud.']);
            }
            if (in_array($data['status'], ['approved', 'in_production', 'ready', 'completed'], true)
                && ! $order) {
                throw ValidationException::withMessages(['status' => 'Primero debe confirmarse el pago mediante Mercado Pago.']);
            }
            if ($order && in_array($data['status'], ['approved', 'in_production', 'ready', 'completed'], true)) {
                $order->assertFulfillable();
            }
            $quote->update($data);
            if ($order && in_array($data['status'], ['rejected', 'cancelled'], true)
                && ! in_array($order->payment_status, ['paid', 'pagado', 'refunded', 'reembolsado'], true)) {
                $order->update(['status' => 'cancelado']);
            } elseif ($order && $data['status'] === 'rejected' && in_array($order->payment_status, ['paid', 'pagado'], true)) {
                $order->forceFill(['fulfillment_issue' => 'Solicitud rechazada después del pago: revisar con el cliente antes de producir o reembolsar.'])->save();
            }
        }, 3);
    }
}
