<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmailValidationService
{
    public function validate(string $email): array
    {
        $apiKey = (string) config('services.mailboxlayer.api_key');
        if (! filled($apiKey)) {
            return [
                'provider' => 'mailboxlayer',
                'status' => 'skipped',
                'valid' => true,
                'reason' => 'Mailboxlayer API key is missing.',
                'details' => [],
            ];
        }

        try {
            $response = Http::timeout((int) config('services.mailboxlayer.timeout', 10))
                ->acceptJson()
                ->get((string) config('services.mailboxlayer.base_url'), [
                    'access_key' => $apiKey,
                    'email' => $email,
                    'smtp' => 1,
                    'format' => 1,
                ]);

            if ($response->failed()) {
                Log::warning('Mailboxlayer email validation failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'provider' => 'mailboxlayer',
                    'status' => 'error',
                    'valid' => true,
                    'reason' => 'Email validator temporarily unavailable.',
                    'details' => [],
                ];
            }

            $payload = $response->json() ?? [];

            if (data_get($payload, 'success') === false || data_get($payload, 'error')) {
                Log::warning('Mailboxlayer returned an error response.', [
                    'error' => data_get($payload, 'error'),
                ]);

                return [
                    'provider' => 'mailboxlayer',
                    'status' => 'error',
                    'valid' => true,
                    'reason' => 'Email validator returned an error.',
                    'details' => [
                        'provider_error' => data_get($payload, 'error.info')
                            ?? data_get($payload, 'error.message')
                            ?? data_get($payload, 'error'),
                    ],
                ];
            }

            $hasProviderValidation = array_key_exists('format_valid', $payload);
            if (! $hasProviderValidation) {
                return [
                    'provider' => 'mailboxlayer',
                    'status' => 'error',
                    'valid' => true,
                    'reason' => 'Email validator returned an incomplete response.',
                    'details' => [],
                ];
            }

            $format = (bool) data_get($payload, 'format_valid');
            $mx = (bool) data_get($payload, 'mx_found', true);
            $smtp = (bool) data_get($payload, 'smtp_check', true);
            $disposable = (bool) data_get($payload, 'disposable', false);
            $score = data_get($payload, 'score');
            $scoreOk = ! is_numeric($score) || (float) $score >= 0.5;
            $valid = $format && $mx && $smtp && ! $disposable && $scoreOk;

            return [
                'provider' => 'mailboxlayer',
                'status' => 'validated',
                'valid' => $valid,
                'reason' => $valid ? 'Email appears deliverable.' : 'Email is invalid, risky, or disposable.',
                'details' => [
                    'format_valid' => $format,
                    'mx_found' => $mx,
                    'smtp_check' => $smtp,
                    'disposable' => $disposable,
                    'free' => (bool) data_get($payload, 'free', false),
                    'score' => $score,
                ],
            ];
        } catch (\Throwable $exception) {
            Log::warning('Mailboxlayer validation exception.', ['error' => $exception->getMessage()]);

            return [
                'provider' => 'mailboxlayer',
                'status' => 'error',
                'valid' => true,
                'reason' => 'Email validator request failed.',
                'details' => [],
            ];
        }
    }

    public function shouldBlock(array $result): bool
    {
        $strict = (bool) config('services.mailboxlayer.strict', false);
        if (! $strict) {
            return false;
        }

        return ($result['status'] ?? '') === 'validated'
            && ($result['valid'] ?? true) === false;
    }
}
