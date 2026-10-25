<?php

declare(strict_types=1);

namespace Coldtill\Payment\Model\Config\Backend;

use Coldtill\Payment\Model\Api\Rules;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class BaseUrl extends Value
{
    public function beforeSave()
    {
        $url = rtrim(trim((string) $this->getValue()), '/');
        if (!Rules::isAllowedBaseUrl($url)) {
            throw new LocalizedException(__('Coldtill URL must start with https:// so the API key is not sent in the clear.'));
        }
        $this->setValue($url);
        return parent::beforeSave();
    }
}
