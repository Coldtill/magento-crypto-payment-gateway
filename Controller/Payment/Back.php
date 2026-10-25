<?php

declare(strict_types=1);

namespace Coldtill\Payment\Controller\Payment;

use Coldtill\Payment\Model\Config;
use Coldtill\Payment\Model\PaymentSync;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * The Coldtill checkout's "Return to store" button lands here. As a fallback for missed webhooks it re-checks the
 * payment with Coldtill (never trusting the visit itself), then shows the order success page.
 */
class Back implements HttpGetActionInterface
{
    public function __construct(
        private Session $checkoutSession,
        private Config $config,
        private PaymentSync $sync,
        private RedirectFactory $redirectFactory,
        private ManagerInterface $messages,
        private LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();
        $order    = $this->checkoutSession->getLastRealOrder();
        $payment  = $order->getId() ? $order->getPayment() : null;
        $ref      = $payment ? (string) $payment->getAdditionalInformation(Config::REFERENCE) : '';
        if (!$payment || $payment->getMethod() !== Config::METHOD_CODE || $ref === '') {
            return $redirect->setPath('checkout/cart');
        }

        if ($order->getBaseTotalPaid() <= 0 && !$order->isCanceled()) {
            try {
                $p = $this->config->client($order->getStoreId())->getPayment($ref);
                $this->sync->apply(
                    (int) $order->getId(),
                    $ref,
                    (string) ($p['paymentState'] ?? ''),
                    (string) ($p['amountInUSD'] ?? ''),
                    (string) ($p['deposits'][0]['transactionHash'] ?? '')
                );
            } catch (\Exception $e) {
                $this->logger->warning($e->getMessage(), ['order' => $order->getIncrementId()]);
            }
        }

        // getLastRealOrder() caches the order, so ask the database what the sync did.
        $state = $this->sync->state((int) $order->getId());
        if ($state === Order::STATE_CANCELED) {
            $this->messages->addErrorMessage(__('This crypto payment was cancelled. Please place your order again.'));
            return $redirect->setPath('checkout/cart');
        }
        return $redirect->setPath('checkout/onepage/success');
    }
}
