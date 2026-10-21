<?php

namespace App\Services\Payments\Gateways\Concerns;

use App\Services\Payments\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait CallsGatewayApi
{
    abstract protected function secretKey(): ?string;

    abstract protected function baseUrl(): string;

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl(), '/'))
            ->withToken((string) $this->secretKey())
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('payments.http_timeout_seconds', 15));
    }

    /**
     * Sends a request. Only connection failures are retried, and only for reads: re-sending a create could double charge.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws PaymentGatewayException when the gateway is unreachable
     */
    private function send(string $method, string $path, array $body = [], bool $idempotentRead = false): Response
    {
        try {
            $client = $this->client();
            if ($idempotentRead) {
                $client = $client->retry(2, 250, fn ($e) => $e instanceof ConnectionException, throw: false);
            }

            return $method === 'GET' ? $client->get($path, $body) : $client->post($path, $body);
        } catch (ConnectionException) {
            throw new PaymentGatewayException('Could not reach '.static::class);
        }
    }

    /** A short note safe to store: the gateway's own `message` only, never the body, headers or keys. */
    private function messageOf(Response $response): string
    {
        $message = $response->json('message');

        return Str::limit(is_string($message) ? $message : 'HTTP '.$response->status(), 180, '');
    }
}
