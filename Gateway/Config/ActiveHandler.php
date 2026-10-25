<?php

declare(strict_types=1);

namespace Coldtill\Payment\Gateway\Config;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Config\ValueHandlerInterface;

/** The method is only active once it is enabled and has a Coldtill URL and an API key. */
class ActiveHandler implements ValueHandlerInterface
{
    public function __construct(private ConfigInterface $config)
    {
    }

    public function handle(array $subject, $storeId = null)
    {
        return (bool) $this->config->getValue('active', $storeId)
            && trim((string) $this->config->getValue('base_url', $storeId)) !== ''
            && trim((string) $this->config->getValue('api_key', $storeId)) !== '';
    }
}
