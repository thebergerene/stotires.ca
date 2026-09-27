<?php
/**
 * Minimal wrapper around Square's Payments API (REST, v2/payments).
 * Deliberately dependency-free (plain cURL) so it drops onto any PHP host
 * without needing Composer or the square/square SDK.
 */

function square_base_url(): string
{
    return SQUARE_ENVIRONMENT === 'production'
        ? 'https://connect.squareup.com'
        : 'https://connect.squareupsandbox.com';
}

/**
 * Creates a payment from a Web Payments SDK token (source_id).
 *
 * @return array{ok: bool, http_status: int, payment: ?array, errors: array, raw: array}
 */
function square_create_payment(
    string $sourceId,
    int $amountCents,
    string $idempotencyKey,
    string $referenceId,
    string $note
): array {
    $payload = [
        'source_id'      => $sourceId,
        'idempotency_key' => $idempotencyKey,
        'location_id'    => SQUARE_LOCATION_ID,
        'reference_id'   => $referenceId, // we put the booking id here
        'note'           => $note,
        'amount_money'   => [
            'amount'   => $amountCents,
            'currency' => SQUARE_CURRENCY,
        ],
    ];

    $ch = curl_init(square_base_url() . '/v2/payments');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Square-Version: ' . SQUARE_API_VERSION,
            'Authorization: Bearer ' . SQUARE_ACCESS_TOKEN,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body    = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        error_log('Square API request failed: ' . $curlErr);
        return ['ok' => false, 'http_status' => 0, 'payment' => null, 'errors' => [['detail' => 'Could not reach the payment provider.']], 'raw' => []];
    }

    $decoded = json_decode($body, true) ?? [];

    return [
        'ok'          => $status === 200 && isset($decoded['payment']),
        'http_status' => $status,
        'payment'     => $decoded['payment'] ?? null,
        'errors'      => $decoded['errors'] ?? [],
        'raw'         => $decoded,
    ];
}

/**
 * Turns Square's error array into one readable sentence for display to the customer.
 * Square's error `detail` text is written to be shown to buyers, so this is safe to surface directly.
 */
function square_error_message(array $errors): string
{
    if (empty($errors)) {
        return 'The payment could not be processed. Please check your card details and try again.';
    }
    $first = $errors[0];
    return $first['detail'] ?? ('Payment failed (' . ($first['code'] ?? 'unknown error') . '). Please try again.');
}
