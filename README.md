# Magento crypto payment gateway: Coldtill for Magento 2 and Adobe Commerce

**Accept USDT, USDC, Bitcoin, Ethereum, Solana, Tron and 200+ coins in Magento, straight to your own wallets.**
No sign-up, no KYB, no custody and no processor that can freeze your money.

This module connects your Magento 2 or Adobe Commerce store to [Coldtill](https://coldtill.com), a
[self-hosted crypto payment gateway](https://github.com/Coldtill/self-hosted-crypto-payment-gateway) that runs on
your own server.

[Download the module](https://github.com/Coldtill/magento-crypto-payment-gateway/releases/latest/download/coldtill-magento.zip) ·
[Coldtill](https://coldtill.com) · [Docs](https://coldtill.com/docs/) · [Live demo dashboard](https://demo.coldtill.com/)

![Coldtill checkout: choose a network and coin, then scan the QR code](https://coldtill.com/img/checkout-pay.webp)

## Features

- **200+ coins on 35+ chains.** BTC, ETH, SOL, TRX, BNB, POL, USDT and USDC directly, and XRP, TON, Sui, Cardano,
  Dogecoin and more through auto-convert, settled to you as USDC or USDT.
- **Self-custody.** On Ethereum, Base, Polygon, BNB Chain, Tron and Solana each customer pays into a deposit contract
  that can only send to your cold wallet. Bitcoin goes to your own wallet's addresses.
- **No account to freeze.** No sign-up, no KYC or KYB, no reserves and no payout schedule.
- **Standard Magento checkout.** Coldtill appears as a payment method; after **Place Order** the customer goes to
  the Coldtill checkout and comes back to the order success page.
- **Invoices created for you.** A paid order gets an invoice and moves to Processing, so your usual fulfilment
  flow carries on.
- **Signed webhooks** (HMAC-SHA256 with a timestamp and replay window). Orders update automatically and are re-checked
  when the customer returns to your store if a webhook is missed.
- **Safe by default.** The order only changes when the reference and amount match; overpayments and partial payments
  are noted on the order. The API key and webhook secret are stored encrypted.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.6+, PHP 8.1–8.3 with the curl extension
- Base currency: USD (Stores → Configuration → General → Currency Setup). Coldtill is charged the order's base
  amount, so a store can still show prices in other display currencies.
- A running Coldtill server. The Free edition installs with one command:

```bash
curl -fsSL https://coldtill.com/install.sh | sudo bash -s -- --edition free
```

## Install

### With composer (recommended)

```bash
composer config repositories.coldtill vcs https://github.com/Coldtill/magento-crypto-payment-gateway
composer require coldtill/module-payment
bin/magento module:enable Coldtill_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile            # production mode only
bin/magento setup:static-content:deploy # production mode only
bin/magento cache:flush
```

### From the zip

1. Download [`coldtill-magento.zip`](https://github.com/Coldtill/magento-crypto-payment-gateway/releases/latest/download/coldtill-magento.zip).
2. Unzip it into `app/code`, so the module ends up in `app/code/Coldtill/Payment`.
3. Run the `bin/magento` commands above, from `module:enable` on.

## Setup

1. In Coldtill, create a project API key (**API keys**) and copy it.
2. In Magento admin, go to **Stores → Configuration → Sales → Payment Methods → Coldtill (crypto)**.
3. Set **Enabled** to Yes and enter your Coldtill URL (`https://`; `http://` only for localhost) and the API key.
4. In Coldtill → **Webhooks**, set the URL shown under "Webhook secret" (it ends in `/coldtill/webhook/`) and paste
   the signing secret into the module settings. If your store views have their own domains or Coldtill settings,
   switch the configuration scope to the store view to see its webhook URL.
5. Make sure **Stores → Configuration → Sales → Sales → Orders Cron Settings → Pending Payment Order Lifetime**
   (480 minutes by default) is at least your Coldtill payment expiry (24 hours by default), or Magento may cancel
   an order the customer is still paying. A payment that arrives after that is noted on the cancelled order for you
   to settle by hand.

Logs go to `var/log/coldtill.log`.

## Order statuses

| Coldtill payment | Magento order |
|---|---|
| Waiting for payment | Pending Payment |
| Filled or overpaid | Processing, with a paid invoice (overpayment noted) |
| Partially paid | On Hold |
| Cancelled or replaced | Canceled (after a partial payment it stays On Hold with a note to settle by hand) |

The order confirmation email is sent once the payment is received.

## Security

Report vulnerabilities to [security@coldtill.com](mailto:security@coldtill.com). See
[coldtill.com/security](https://coldtill.com/security).

## Licence

MIT. See [LICENSE](LICENSE).
