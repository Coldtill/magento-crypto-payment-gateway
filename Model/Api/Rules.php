<?php

declare(strict_types=1);

namespace Coldtill\Payment\Model\Api;

/**
 * Decisions that don't depend on Magento: webhook signatures, the Coldtill URL rule and what a payment state means
 * for an order. Kept free of Magento classes so Test/run.php can exercise it with plain PHP.
 */
final class Rules
{
    public const NONE           = 'none';
    public const MISMATCH       = 'mismatch';
    public const PAID           = 'paid';
    public const OVERPAID       = 'overpaid';
    public const HOLD           = 'hold';
    public const CANCEL         = 'cancel';
    public const SETTLE_BY_HAND = 'settle_by_hand';

    public const MAX_AGE = 300;

    /** Verifies the timestamped X-Coldtill-Signature-V2 header and rejects deliveries older than five minutes. */
    public static function verifySignature(string $rawBody, string $header, string $timestamp, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || !str_starts_with($header, 'sha256=') || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > self::MAX_AGE) {
            return false;
        }
        $want = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        return hash_equals($want, strtolower(trim($header)));
    }

    /**
     * Servers send each header as X-Coldtill-* and as the pre-rename X-Keyhold-*.
     *
     * @param callable(string):(string|false|null) $get returns a request header by name
     */
    public static function header(callable $get, string $name): string
    {
        foreach (['X-Coldtill-', 'X-Keyhold-'] as $prefix) {
            $value = $get($prefix . $name);
            if (is_string($value) && $value !== '') {
                return trim($value);
            }
        }
        return '';
    }

    /** Only https keeps the API key private on the way to Coldtill; plain http is accepted for localhost testing. */
    public static function isAllowedBaseUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        return $scheme === 'https' || ($scheme === 'http' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true));
    }

    /**
     * What a Coldtill payment state means for an unpaid order.
     *
     * @param string $amount     amount owed according to Coldtill, '' when not given
     * @param float  $orderTotal order total in USD
     * @param bool   $partlyPaid the order is already held for a partial payment
     */
    public static function action(string $status, string $amount, float $orderTotal, bool $partlyPaid): string
    {
        // Compared in cents: as floats, 25 - 24.99 comes out a hair above 0.01.
        if ($amount !== '' && abs(round((float) $amount * 100) - round($orderTotal * 100)) > 1) {
            return self::MISMATCH;
        }
        switch ($status) {
            case 'FILLED':
                return self::PAID;
            case 'OVER_FILLED':
                return self::OVERPAID;
            case 'PARTIALLY_FILLED':
                return $partlyPaid ? self::NONE : self::HOLD;
            case 'CANCELLED':
            case 'REPLACED':
                // Part of the money has arrived (a logged-in customer's next checkout replaces this payment), so a
                // person has to settle the order rather than have it cancelled.
                return $partlyPaid ? self::SETTLE_BY_HAND : self::CANCEL;
        }
        return self::NONE;
    }

    /** Coldtill allows one open payment per customer, so guests get a per-order id. */
    public static function customerId(?int $customerId, int $orderId): string
    {
        return $customerId ? 'mg-customer-' . $customerId : 'mg-order-' . $orderId;
    }

    public static function invoiceId(string $incrementId): string
    {
        return 'MG-' . $incrementId;
    }

    /** The order increment id inside an invoiceID this module created, or '' for anything else. */
    public static function incrementIdFromInvoice(string $invoiceId): string
    {
        return str_starts_with($invoiceId, 'MG-') ? substr($invoiceId, 3) : '';
    }
}
