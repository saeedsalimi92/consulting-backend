<?php
namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** SMS.ir Verify API adapter. Sandbox and Production credentials are never interchangeable. */
final class SmsIrProvider implements SmsProvider
{
    public function send(string $phone,string $message,string $idempotencyKey): array
    {
        // Only the documented Verify API contract is enabled here. Plain text send
        // needs a separate, verified provider contract and must fail closed.
        throw new RuntimeException('SMS.ir plain-text delivery is not configured.');
    }

    public function sendTemplate(string $phone,string $template,array $parameters,string $idempotencyKey): array
    {
        $mode = config('services.sms_ir.mode');
        if (!in_array($mode, ['sandbox', 'production'], true)) {
            throw new RuntimeException('SMS.ir mode is invalid; delivery is disabled.');
        }

        if ($mode === 'sandbox') {
            $key = config('services.sms_ir.sandbox_api_key');
        } else {
            if (!config('services.sms_ir.live_send_enabled')) {
                throw new RuntimeException('SMS.ir Production sending is disabled.');
            }
            $key = config('services.sms_ir.production_api_key');
        }
        if (!is_string($key) || trim($key) === '') {
            throw new RuntimeException('SMS.ir credential for the selected mode is missing.');
        }

        $baseUrl = rtrim((string) config('services.sms_ir.base_url'), '/');
        $url = parse_url($baseUrl);
        if (!$url || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== 'api.sms.ir'
            || ($url['path'] ?? '') !== '/v1' || isset($url['user']) || isset($url['pass']) || isset($url['query'])) {
            throw new RuntimeException('SMS.ir API URL is invalid; delivery is disabled.');
        }

        if (!preg_match('/^9\d{9}$/', $phone)) {
            throw new RuntimeException('SMS.ir recipient format is invalid.');
        }
        if (!preg_match('/^\d+$/', (string) $template)) {
            throw new RuntimeException('SMS.ir template is not configured.');
        }
        $formattedParameters = [];
        foreach ($parameters as $name => $value) {
            if (!is_string($name) || $name === '' || !is_scalar($value)) {
                throw new RuntimeException('SMS.ir template parameters are invalid.');
            }
            $formattedParameters[] = ['name' => $name, 'value' => (string) $value];
        }
        if ($formattedParameters === []) {
            throw new RuntimeException('SMS.ir template parameters are missing.');
        }

        try {
            $response = Http::asJson()
                ->accept('text/plain')
                ->withHeaders(['x-api-key' => $key])
                ->connectTimeout(3)
                ->timeout(8)
                ->post($baseUrl.'/send/verify', [
                    'mobile' => $phone,
                    'templateId' => (int) $template,
                    'parameters' => $formattedParameters,
                ]);
        } catch (ConnectionException $exception) {
            if (app()->environment('local')) {
                Log::warning('SMS.ir transport failure', [
                    'category' => $this->transportCategory($exception->getMessage()),
                    'exception_type' => get_class($exception),
                    'diagnostic' => $this->sanitizeTransportMessage($exception->getMessage(), $key, $phone, $parameters),
                ]);
            }
            throw new RuntimeException('SMS.ir provider connection failed.');
        }

        if ($response->status() === 429) {
            throw new RuntimeException('SMS.ir provider rate limited the request.');
        }
        if ($response->serverError()) {
            throw new RuntimeException('SMS.ir provider is temporarily unavailable.');
        }
        if (!$response->successful()) {
            throw new RuntimeException('SMS.ir rejected the request.');
        }

        $body = $response->json();
        if (!is_array($body) || !array_key_exists('status', $body)) {
            throw new RuntimeException('SMS.ir returned an invalid response.');
        }
        if ($body['status'] !== 1) {
            throw new RuntimeException('SMS.ir did not accept the request.');
        }

        $data = $body['data'] ?? [];
        if (!is_array($data)) {
            throw new RuntimeException('SMS.ir returned an invalid response.');
        }
        $messageId = $data['messageId'] ?? null;
        $result = [
            'status' => $mode === 'sandbox' ? 'sandbox_simulated' : 'accepted',
            'delivery_mode' => $mode,
            'reference' => $messageId === null ? null : (string) $messageId,
            'provider_status' => 1,
        ];
        if (array_key_exists('cost', $data) && is_numeric($data['cost'])) {
            $result['cost'] = (float) $data['cost'];
            if ($mode === 'sandbox') $result['cost_type'] = 'simulated';
        }
        return $result;
    }

    private function transportCategory(string $message): string
    {
        $message = strtolower($message);
        if (str_contains($message, 'proxy')) return 'proxy_failure';
        if (str_contains($message, 'could not resolve host') || str_contains($message, 'name resolution') || str_contains($message, 'getaddrinfo')) return 'dns_resolution_failure';
        if (str_contains($message, 'certificate verify') || str_contains($message, 'certificate problem')) return 'tls_certificate_failure';
        if (str_contains($message, 'ssl connect') || str_contains($message, 'tls handshake') || str_contains($message, 'handshake failure')) return 'tls_handshake_failure';
        if (str_contains($message, 'connection refused') || str_contains($message, 'failed to connect')) return 'tcp_connection_failure';
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) return 'connection_timeout';
        return 'transport_failure';
    }

    private function sanitizeTransportMessage(string $message, string $key, string $phone, array $parameters): string
    {
        $sensitive = [$key, $phone];
        foreach ($parameters as $value) if (is_scalar($value)) $sensitive[] = (string) $value;
        $message = str_replace(array_filter($sensitive, static fn ($value) => $value !== ''), '[redacted]', $message);
        return substr(preg_replace('/\s+/', ' ', $message) ?? 'Transport failure', 0, 300);
    }
}
