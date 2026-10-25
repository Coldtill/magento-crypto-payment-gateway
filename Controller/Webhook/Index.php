<?php

declare(strict_types=1);

namespace Coldtill\Payment\Controller\Webhook;

use Coldtill\Payment\Model\Api\Rules;
use Coldtill\Payment\Model\Config;
use Coldtill\Payment\Model\PaymentSync;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Psr\Log\LoggerInterface;

/** Receives signed Coldtill webhooks at /coldtill/webhook/. */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private Http $request,
        private RawFactory $rawFactory,
        private Config $config,
        private PaymentSync $sync,
        private LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $raw    = (string) $this->request->getContent();
        $header = fn (string $name) => $this->request->getHeader($name);
        // The webhook URL is per store view, so the store Magento resolved for this request owns the secret.
        $secret = $this->config->webhookSecret();
        if (!Rules::verifySignature($raw, Rules::header($header, 'Signature-V2'), Rules::header($header, 'Timestamp'), $secret)) {
            return $this->respond(401);
        }

        $event = Rules::header($header, 'Event');
        $data  = json_decode($raw, true);
        if (!str_starts_with($event, 'payment.') || !is_array($data) || empty($data['reference_id'])) {
            return $this->respond(200);
        }
        try {
            foreach ($this->sync->orderIdsForInvoice((string) ($data['invoice_id'] ?? '')) as $orderId) {
                $this->sync->apply(
                    $orderId,
                    (string) $data['reference_id'],
                    (string) ($data['status'] ?? ''),
                    (string) ($data['amount'] ?? ''),
                    (string) ($data['payment_info'][0]['transaction_hash'] ?? '')
                );
            }
        } catch (\Exception $e) {
            // A non-2xx answer makes Coldtill retry the delivery later.
            $this->logger->error('Coldtill webhook: ' . $e->getMessage());
            return $this->respond(500);
        }
        return $this->respond(200);
    }

    private function respond(int $code): Raw
    {
        return $this->rawFactory->create()->setHttpResponseCode($code)->setContents('');
    }

    // Coldtill can't send a form key; the HMAC signature authenticates the request instead.
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
