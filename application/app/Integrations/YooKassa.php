<?php

namespace App\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YooKassa
{
    private const BASE = 'https://api.yookassa.ru/v3/';

    public function enabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('portal.yookassa.sandbox_enabled') && filled(config('portal.yookassa.shop_id')) && str_starts_with((string) config('portal.yookassa.secret_key'), 'test_');
    }

    private function client(): PendingRequest
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Sandbox not configured');
        }

        return Http::baseUrl(self::BASE)->withBasicAuth((string) config('portal.yookassa.shop_id'), (string) config('portal.yookassa.secret_key'))->acceptJson()->asJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
    }

    public function create(string $kind, string $key, array $payload): array
    {
        $response = $this->client()->withHeaders(['Idempotence-Key' => $key])->post($kind === 'payment' ? 'payments' : 'refunds', $payload);
        $response->throw();
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Invalid provider response');
        }

        return $data;
    }

    public function fetch(string $kind, string $id): array
    {
        if (! preg_match('/^[a-f0-9-]{36}$/D', $id)) {
            throw new RuntimeException('Invalid provider ID');
        }
        $response = $this->client()->get(($kind === 'payment' ? 'payments/' : 'refunds/').$id);
        $response->throw();
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Invalid provider response');
        }

        return $data;
    }
}
