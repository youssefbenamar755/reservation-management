<?php

namespace App\Support;

class FluentWebhookPayload
{
    public static function normalize(array $payload): array
    {
        // Older queued events included the URL credential alongside the body.
        unset($payload['token']);

        if (array_is_list($payload) && count($payload) === 1 && is_array($payload[0])) {
            $payload = $payload[0];
        }

        unset($payload['token']);

        return $payload;
    }

    public static function redact(array $payload): array
    {
        unset($payload['token']);

        if (isset($payload['response']) && is_array($payload['response'])) {
            unset($payload['response']['token']);
        } elseif (isset($payload['response']) && is_string($payload['response'])) {
            $response = json_decode($payload['response'], true);
            if (is_array($response) && array_key_exists('token', $response)) {
                unset($response['token']);
                $payload['response'] = json_encode($response, JSON_THROW_ON_ERROR);
            }
        }

        return $payload;
    }
}
