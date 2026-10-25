<?php

declare(strict_types=1);

namespace Coldtill\Payment\Model;

use Coldtill\Payment\Model\Api\Client;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads payment/coldtill/*. The API key and webhook secret come back decrypted: config.xml declares the Encrypted
 * backend model for them, and Magento's config metadata processor decrypts such values on read.
 */
class Config
{
    public const METHOD_CODE = 'coldtill';
    public const REFERENCE   = 'coldtill_reference_id';
    public const CHECKOUT    = 'coldtill_checkout_url';

    public function __construct(private ScopeConfigInterface $scopeConfig)
    {
    }

    public function baseUrl(int|string|null $storeId = null): string
    {
        return trim((string) $this->value('base_url', $storeId));
    }

    public function apiKey(int|string|null $storeId = null): string
    {
        return trim((string) $this->value('api_key', $storeId));
    }

    public function webhookSecret(int|string|null $storeId = null): string
    {
        return trim((string) $this->value('webhook_secret', $storeId));
    }

    public function client(int|string|null $storeId = null): Client
    {
        return new Client($this->baseUrl($storeId), $this->apiKey($storeId));
    }

    private function value(string $field, int|string|null $storeId): mixed
    {
        return $this->scopeConfig->getValue('payment/' . self::METHOD_CODE . '/' . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
