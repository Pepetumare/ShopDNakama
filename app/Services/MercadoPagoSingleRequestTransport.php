<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;

class MercadoPagoSingleRequestTransport implements MPHttpClient
{
    public function send(MPRequest $request): MPResponse
    {
        $headers = [];
        foreach ($request->getHeaders() ?? [] as $header) {
            [$name, $value] = explode(':', $header, 2);
            $headers[$name] = trim($value);
        }

        // The SDK default transport retries failed POSTs and only bounds connection setup.
        $http = Http::withHeaders($headers)->withoutRedirecting()->connectTimeout(4)->timeout(10);
        if ($request->getPayload() !== null) {
            $http->withBody($request->getPayload(), 'application/json');
        }
        $response = $http->send($request->getMethod(), MercadoPagoConfig::$BASE_URL.$request->getUri());
        $body = $response->json();
        $result = new MPResponse($response->status(), is_array($body) ? $body : []);
        if (! $response->successful()) {
            throw new MPApiException('Mercado Pago preference request failed.', $result);
        }

        return $result;
    }
}
