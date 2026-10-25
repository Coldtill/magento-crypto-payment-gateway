<?php

declare(strict_types=1);

namespace Coldtill\Payment\Model\Config;

use Magento\Config\Model\Config\CommentInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Shows the webhook URL of the store view (or website) whose configuration is being edited. */
class WebhookComment implements CommentInterface
{
    public function __construct(
        private RequestInterface $request,
        private StoreManagerInterface $storeManager,
        private Escaper $escaper
    ) {
    }

    public function getCommentText($elementValue)
    {
        try {
            $storeId = (int) $this->request->getParam('store');
            $website = (int) $this->request->getParam('website');
            if ($storeId) {
                $store = $this->storeManager->getStore($storeId);
            } elseif ($website) {
                $store = $this->storeManager->getWebsite($website)->getDefaultStore();
            } else {
                $store = $this->storeManager->getDefaultStoreView();
            }
            $url = $store ? $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true) . 'coldtill/webhook/' : '';
        } catch (\Exception $e) {
            $url = '';
        }
        if ($url === '') {
            return (string) __('In Coldtill → Webhooks, add your store URL followed by coldtill/webhook/ and paste the signing secret here.');
        }
        return (string) __(
            'In Coldtill → Webhooks, set the URL to %1 and paste the signing secret here.',
            '<code>' . $this->escaper->escapeHtml($url) . '</code>'
        );
    }
}
