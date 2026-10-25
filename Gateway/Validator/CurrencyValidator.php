<?php

declare(strict_types=1);

namespace Coldtill\Payment\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;

/**
 * Coldtill prices in USD. Magento passes the base currency here (Checks\CanUseForCurrency), and the base amount is
 * what this module charges and registers as captured.
 */
class CurrencyValidator extends AbstractValidator
{
    public function validate(array $validationSubject): ResultInterface
    {
        $ok = ($validationSubject['currency'] ?? '') === 'USD';
        return $this->createResult($ok, $ok ? [] : [__('Crypto payment is only available for USD.')]);
    }
}
