<?php

declare(strict_types=1);

namespace Coldtill\Payment\Gateway\Command;

use Magento\Framework\DataObject;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Sales\Model\Order;

/**
 * Runs at place order instead of authorize/capture. The Coldtill payment is created afterwards by the redirect
 * controller; until it is paid the order waits in pending_payment.
 */
class InitializeCommand implements CommandInterface
{
    public function execute(array $commandSubject)
    {
        $state = $commandSubject['stateObject'] ?? null;
        if ($state instanceof DataObject) {
            $state->setData('state', Order::STATE_PENDING_PAYMENT);
            $state->setData('status', Order::STATE_PENDING_PAYMENT);
            $state->setData('is_notified', false);
        }
        return null;
    }
}
