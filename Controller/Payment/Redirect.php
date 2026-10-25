<?php

declare(strict_types=1);

namespace Coldtill\Payment\Controller\Payment;

use Coldtill\Payment\Model\Config;
use Coldtill\Payment\Model\PaymentStarter;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/** The checkout sends the customer here after place order; this creates the Coldtill payment and redirects to it. */
class Redirect implements HttpGetActionInterface
{
    public function __construct(
        private Session $checkoutSession,
        private PaymentStarter $starter,
        private RedirectFactory $redirectFactory,
        private ManagerInterface $messages,
        private OrderRepositoryInterface $orderRepository,
        private LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();
        $order    = $this->checkoutSession->getLastRealOrder();
        $payment  = $order->getId() ? $order->getPayment() : null;
        if (!$payment || $payment->getMethod() !== Config::METHOD_CODE || $order->getState() !== Order::STATE_PENDING_PAYMENT) {
            return $redirect->setPath('checkout/cart');
        }

        try {
            return $redirect->setUrl($this->starter->start($order));
        } catch (LocalizedException $e) {
            $this->messages->addErrorMessage($e->getMessage());
        }

        // No Coldtill payment exists, so give the customer their cart back to choose another method.
        try {
            if (!$payment->getAdditionalInformation(Config::REFERENCE) && $order->canCancel()) {
                $order->cancel();
                $order->addCommentToStatusHistory('Coldtill payment could not be created; order cancelled and cart restored.');
                $this->orderRepository->save($order);
            }
            $this->checkoutSession->restoreQuote();
        } catch (\Exception $e) {
            $this->logger->error('Coldtill: could not restore the cart: ' . $e->getMessage());
        }
        return $redirect->setPath('checkout/cart');
    }
}
