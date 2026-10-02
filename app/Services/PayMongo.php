<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * PayMongo's QR Ph API (2026-10-02, at the user's request) -- the one place
 * the app talks to PayMongo.
 *
 * A personal GCash QR pays straight into the shop's wallet and tells nobody,
 * so the till could never know a customer had paid. A PayMongo QR Ph code is
 * made per sale, with the amount in it, and PayMongo can be ASKED whether it
 * was paid (intentStatus()) and TELLS us when it is (the payment.paid
 * webhook). Flow, per docs.paymongo.com "QR Ph API":
 *
 *   1. create a Payment Intent (secret key): amount in centavos, PHP,
 *      payment_method_allowed ["qrph"]
 *   2. create a Payment Method of type "qrph" (public key)
 *   3. attach it to the intent (public key + the intent's client_key)
 *      -> status awaiting_next_action, QR at next_action.code.image_url
 *      (a base64 data URI)
 *   4. the intent becomes "succeeded" once the customer pays
 *
 * Keys come from config('services.paymongo'), set by the account owner in
 * the hosting dashboards -- never in the code (the repository is public).
 * In TEST mode the QR is a real one that must NOT be scanned and paid; the
 * response carries a test_url that simulates payment instead.
 */
class PayMongo
{
    public const BASE_URL = 'https://api.paymongo.com/v1';

    /** PayMongo's floor for QR Ph: PHP 1.00. */
    public const MIN_CENTAVOS = 100;

    public static function enabled(): bool
    {
        return filled(config('services.paymongo.secret_key')) && filled(config('services.paymongo.public_key'));
    }

    public static function isTestMode(): bool
    {
        return str_starts_with((string) config('services.paymongo.secret_key'), 'sk_test_');
    }

    /**
     * A QR Ph code for this amount.
     *
     * @return array{intent_id: string, image: string, test_url: ?string}
     *
     * @throws PayMongoException
     */
    public function createQrPh(int $centavos, string $description, int $expirySeconds = 900): array
    {
        $intent = $this->send('post', '/payment_intents', 'secret', ['data' => ['attributes' => [
            'amount' => $centavos,
            'currency' => 'PHP',
            'payment_method_allowed' => ['qrph'],
            'description' => $description,
        ]]]);

        $intentId = (string) data_get($intent, 'data.id');
        $clientKey = (string) data_get($intent, 'data.attributes.client_key');

        $method = $this->send('post', '/payment_methods', 'public', ['data' => ['attributes' => [
            'type' => 'qrph',
            'expiry_seconds' => $expirySeconds,
        ]]]);

        $attached = $this->send('post', "/payment_intents/{$intentId}/attach", 'public', ['data' => ['attributes' => [
            'payment_method' => (string) data_get($method, 'data.id'),
            'client_key' => $clientKey,
        ]]]);

        $image = (string) data_get($attached, 'data.attributes.next_action.code.image_url');

        if ($intentId === '' || $image === '') {
            throw new PayMongoException('PayMongo did not return a QR code.');
        }

        return [
            'intent_id' => $intentId,
            'image' => str_starts_with($image, 'data:') ? $image : 'data:image/png;base64,'.$image,
            // Its exact place in the response is not documented, so it is
            // looked for anywhere in next_action. Only ever shown in test mode.
            'test_url' => self::isTestMode() ? self::findKey((array) data_get($attached, 'data.attributes.next_action', []), 'test_url') : null,
        ];
    }

    /** The intent's status: "awaiting_next_action", "succeeded", "processing", ... */
    public function intentStatus(string $intentId): ?string
    {
        $intent = $this->send('get', '/payment_intents/'.rawurlencode($intentId), 'secret');

        return data_get($intent, 'data.attributes.status');
    }

    /**
     * Whether a webhook really came from PayMongo. Header:
     * "t=<timestamp>,te=<test sig>,li=<live sig>"; the signature is
     * HMAC-SHA256 over "<t>.<raw body>" with the webhook's secret key.
     */
    public static function verifySignature(string $rawBody, ?string $header, ?string $secret): bool
    {
        if (! $header || ! $secret) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$k] = $v;
        }

        if (($parts['t'] ?? '') === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $parts['t'].'.'.$rawBody, $secret);

        foreach (['li', 'te'] as $mode) {
            if (($parts[$mode] ?? '') !== '' && hash_equals($expected, $parts[$mode])) {
                return true;
            }
        }

        return false;
    }

    /** @throws PayMongoException */
    private function send(string $verb, string $path, string $key, array $body = []): array
    {
        $apiKey = (string) config('services.paymongo.'.$key.'_key');

        try {
            $response = Http::withBasicAuth($apiKey, '')
                ->acceptJson()
                ->timeout(15)
                ->{$verb}(self::BASE_URL.$path, $verb === 'get' ? null : $body)
                ->throw();
        } catch (RequestException $e) {
            // PayMongo explains a refusal in errors[0].detail.
            $detail = data_get($e->response?->json(), 'errors.0.detail');
            throw new PayMongoException($detail ? (string) $detail : 'PayMongo refused the request.', 0, $e);
        } catch (\Throwable $e) {
            throw new PayMongoException('Could not reach PayMongo. Check the internet connection.', 0, $e);
        }

        return (array) $response->json();
    }

    private static function findKey(array $haystack, string $key): ?string
    {
        foreach ($haystack as $k => $v) {
            if ($k === $key && is_string($v)) {
                return $v;
            }
            if (is_array($v) && ($found = self::findKey($v, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
