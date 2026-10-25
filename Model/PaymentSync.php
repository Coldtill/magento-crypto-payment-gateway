<?php

declare(strict_types=1);

namespace Coldtill\Payment\Model;

use Coldtill\Payment\Model\Api\Rules;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Psr\Log\LoggerInterface;

/** Applies a Coldtill payment state to its order, from a webhook or from the return-page re-check. */
class PaymentSync
{
    public function __construct(
        private CollectionFactory $orders,
        private OrderRepositoryInterface $orderRepository,
        private LockManagerInterface $locks,
        private OrderSender $orderSender,
        private LoggerInterface $logger
    ) {
    }

    /** @return int[] ids of the orders an invoiceID made by this module can belong to (one per store at most) */
    public function orderIdsForInvoice(string $invoiceId): array
    {
        $increment = Rules::incrementIdFromInvoice($invoiceId);
        if ($increment === '') {
            return [];
        }
        return array_map('intval', $this->orders->create()->addFieldToFilter('increment_id', $increment)->getAllIds());
    }

    public function state(int $orderId): string
    {
        return (string) $this->orders->create()->addFieldToFilter('entity_id', $orderId)->getFirstItem()->getState();
    }

    public function apply(int $orderId, string $ref, string $status, string $amount, string $tx): void
    {
        // The webhook and the return page can run at the same time; only one may invoice the order.
        $lock = 'coldtill_order_' . $orderId;
        if (!$this->locks->lock($lock, 15)) {
            throw new \RuntimeException('Coldtill: order ' . $orderId . ' is locked');
        }
        try {
            $order = $this->orders->create()->addFieldToFilter('entity_id', $orderId)->getFirstItem();
            if ($order instanceof Order && $order->getId()) {
                $this->applyToOrder($order, $ref, $status, $amount, $tx);
            }
        } finally {
            $this->locks->unlock($lock);
        }
    }

    private function applyToOrder(Order $order, string $ref, string $status, string $amount, string $tx): void
    {
        $payment = $order->getPayment();
        // A retried checkout gets a new reference; events for the old one must not touch the order.
        if (!$payment || $payment->getMethod() !== Config::METHOD_CODE
            || (string) $payment->getAdditionalInformation(Config::REFERENCE) !== $ref) {
            return;
        }
        if ($order->getBaseTotalPaid() > 0 || $order->hasInvoices()) {
            return;
        }
        if ($order->getBaseCurrencyCode() !== 'USD') {
            $this->comment($order, 'Coldtill payment ignored: the order base currency is not USD.');
            return;
        }

        $action = Rules::action($status, $amount, (float) $order->getBaseGrandTotal(), $order->getState() === Order::STATE_HOLDED);
        switch ($action) {
            case Rules::MISMATCH:
                $this->comment($order, sprintf('Coldtill amount %s USD does not match the order total; not changing status.', $amount));
                break;
            case Rules::PAID:
            case Rules::OVERPAID:
                $this->capture($order, $ref, $tx, $action === Rules::OVERPAID);
                break;
            case Rules::HOLD:
                if ($order->canHold()) {
                    $order->hold();
                    $this->comment($order, 'Partial crypto payment received; waiting for the rest.');
                }
                break;
            case Rules::SETTLE_BY_HAND:
                $this->comment($order, 'Coldtill payment ' . strtolower($status) . ' after a partial payment; check the amount received in Coldtill and settle this order by hand.');
                break;
            case Rules::CANCEL:
                if ($order->canCancel()) {
                    $order->cancel();
                    $this->comment($order, 'Coldtill payment ' . strtolower($status) . '.');
                }
                break;
        }
    }

    private function capture(Order $order, string $ref, string $tx, bool $overpaid): void
    {
        if ($order->canUnhold()) {
            $order->unhold();
        }
        // Magento cancels pending_payment orders after "Pending Payment Order Lifetime"; the money still arrived.
        if ($order->isCanceled()) {
            $this->comment($order, 'Crypto payment received after this order was cancelled; check the payment in Coldtill and settle this order by hand.');
            return;
        }
        $payment = $order->getPayment();
        $payment->setTransactionId($tx !== '' ? $tx : $ref);
        $payment->setCurrencyCode($order->getBaseCurrencyCode());
        $payment->setIsTransactionClosed(true);
        if ($tx !== '') {
            $payment->setAdditionalInformation('coldtill_transaction_hash', $tx);
        }
        $payment->registerCaptureNotification($order->getBaseTotalDue(), true);
        $this->comment($order, $overpaid
            ? 'Crypto payment received (overpaid; refund the difference from your wallet if needed).'
            : 'Crypto payment received.');

        // Magento skipped the order email at checkout because the customer was sent on to Coldtill.
        if (!$order->getEmailSent()) {
            try {
                $this->orderSender->send($order);
            } catch (\Throwable $e) {
                $this->logger->error('Coldtill: order email failed: ' . $e->getMessage());
            }
        }
    }

    private function comment(Order $order, string $text): void
    {
        $order->addCommentToStatusHistory($text);
        $this->orderRepository->save($order);
    }
}
