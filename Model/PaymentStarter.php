<?php

declare(strict_types=1);

namespace Coldtill\Payment\Model;

use Coldtill\Payment\Model\Api\ApiException;
use Coldtill\Payment\Model\Api\Rules;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/** Creates the Coldtill payment for a placed order and returns the hosted checkout URL. */
class PaymentStarter
{
    public function __construct(
        private Config $config,
        private UrlInterface $url,
        private OrderRepositoryInterface $orderRepository,
        private LoggerInterface $logger
    ) {
    }

    /** @throws LocalizedException with a message the customer can see */
    public function start(Order $order): string
    {
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== Config::METHOD_CODE || $order->getState() !== Order::STATE_PENDING_PAYMENT) {
            throw new LocalizedException(__('This order cannot be paid with crypto.'));
        }
        // Reloading the redirect page must not replace the customer's payment with a new one.
        $existing = (string) $payment->getAdditionalInformation(Config::CHECKOUT);
        if ($existing !== '' && $payment->getAdditionalInformation(Config::REFERENCE)) {
            return $existing;
        }
        // The base currency was checked when the method was offered; check again in case it changed since.
        if ($order->getBaseCurrencyCode() !== 'USD') {
            throw new LocalizedException(__('Crypto payment is only available for orders in USD.'));
        }

        $storeId = $order->getStoreId();
        $req = [
            'customerID'    => Rules::customerId($order->getCustomerIsGuest() ? null : (int) $order->getCustomerId(), (int) $order->getId()),
            'customerEmail' => (string) $order->getCustomerEmail(),
            'amountInUSD'   => number_format((float) $order->getBaseGrandTotal(), 2, '.', ''),
            'invoiceID'     => Rules::invoiceId((string) $order->getIncrementId()),
        ];
        // The checkout's "Return to store" button brings the customer back, which also re-checks the payment.
        $return = $this->url->getUrl('coldtill/payment/back', ['_secure' => true, '_scope' => $storeId]);
        if (str_starts_with($return, 'https://')) {
            $req['returnURL'] = $return;
        }

        try {
            $res = $this->config->client($storeId)->createPayment($req);
        } catch (ApiException $e) {
            $this->logger->error($e->getMessage(), ['order' => $order->getIncrementId()]);
            throw new LocalizedException(__('Crypto payment is unavailable right now. Please try again or choose another method.'));
        }

        $payment->setAdditionalInformation(Config::REFERENCE, (string) $res['reference_id']);
        $payment->setAdditionalInformation(Config::CHECKOUT, (string) $res['url']);
        $order->addCommentToStatusHistory('Awaiting crypto payment (Coldtill reference ' . $res['reference_id'] . ').');
        $this->orderRepository->save($order);

        return (string) $res['url'];
    }
}
