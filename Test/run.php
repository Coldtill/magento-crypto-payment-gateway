<?php

// Tests for the Magento-free logic. Run: php Test/run.php

declare(strict_types=1);

require __DIR__ . '/../Model/Api/ApiException.php';
require __DIR__ . '/../Model/Api/Client.php';
require __DIR__ . '/../Model/Api/Rules.php';

use Coldtill\Payment\Model\Api\ApiException;
use Coldtill\Payment\Model\Api\Client;
use Coldtill\Payment\Model\Api\Rules;

$failed = 0;
$passed = 0;
function check(string $name, bool $ok): void
{
    global $failed, $passed;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $ok ? $passed++ : $failed++;
}

// Signatures
$secret = 'whsec_test';
$body   = '{"reference_id":"ref-1","status":"FILLED","amount":"25","invoice_id":"MG-000000001"}';
$now    = 1791647937;
$ts     = (string) $now;
$sig    = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);

check('good signature', Rules::verifySignature($body, $sig, $ts, $secret, $now));
check('good signature, upper-case hex', Rules::verifySignature($body, 'sha256=' . strtoupper(substr($sig, 7)), $ts, $secret, $now));
check('good signature, 299s old', Rules::verifySignature($body, $sig, $ts, $secret, $now + 299));
check('tampered body', !Rules::verifySignature(str_replace('25', '1', $body), $sig, $ts, $secret, $now));
check('tampered timestamp', !Rules::verifySignature($body, $sig, (string) ($now + 1), $secret, $now));
check('stale (301s old)', !Rules::verifySignature($body, $sig, $ts, $secret, $now + 301));
check('from the future (301s ahead)', !Rules::verifySignature($body, $sig, $ts, $secret, $now - 301));
check('wrong secret', !Rules::verifySignature($body, $sig, $ts, 'other', $now));
check('empty secret', !Rules::verifySignature($body, 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, ''), $ts, '', $now));
check('missing sha256= prefix', !Rules::verifySignature($body, substr($sig, 7), $ts, $secret, $now));
check('non-numeric timestamp', !Rules::verifySignature($body, $sig, $ts . 'x', $secret, $now));
check('empty signature', !Rules::verifySignature($body, '', $ts, $secret, $now));

// Header fallback
$headers = fn (array $h) => fn (string $name) => $h[$name] ?? false;
check('X-Coldtill header', Rules::header($headers(['X-Coldtill-Timestamp' => $ts]), 'Timestamp') === $ts);
check('X-Keyhold fallback', Rules::header($headers(['X-Keyhold-Signature-V2' => $sig]), 'Signature-V2') === $sig);
check('X-Coldtill wins over X-Keyhold', Rules::header($headers(['X-Coldtill-Event' => 'payment.filled', 'X-Keyhold-Event' => 'old']), 'Event') === 'payment.filled');
check('missing header', Rules::header($headers([]), 'Event') === '');
$get = $headers(['X-Keyhold-Signature-V2' => $sig, 'X-Keyhold-Timestamp' => $ts]);
check('verify with X-Keyhold headers only', Rules::verifySignature($body, Rules::header($get, 'Signature-V2'), Rules::header($get, 'Timestamp'), $secret, $now));

// Status mapping
check('FILLED -> paid', Rules::action('FILLED', '25', 25.0, false) === Rules::PAID);
check('FILLED on a held order -> paid', Rules::action('FILLED', '25', 25.0, true) === Rules::PAID);
check('OVER_FILLED -> overpaid', Rules::action('OVER_FILLED', '25', 25.0, false) === Rules::OVERPAID);
check('PARTIALLY_FILLED -> hold', Rules::action('PARTIALLY_FILLED', '25', 25.0, false) === Rules::HOLD);
check('PARTIALLY_FILLED again -> none', Rules::action('PARTIALLY_FILLED', '25', 25.0, true) === Rules::NONE);
check('CANCELLED -> cancel', Rules::action('CANCELLED', '25', 25.0, false) === Rules::CANCEL);
check('REPLACED -> cancel', Rules::action('REPLACED', '25', 25.0, false) === Rules::CANCEL);
check('CANCELLED after partial -> settle by hand', Rules::action('CANCELLED', '25', 25.0, true) === Rules::SETTLE_BY_HAND);
check('REPLACED after partial -> settle by hand', Rules::action('REPLACED', '25', 25.0, true) === Rules::SETTLE_BY_HAND);
check('OPEN -> none', Rules::action('OPEN', '25', 25.0, false) === Rules::NONE);
check('unknown -> none', Rules::action('', '25', 25.0, false) === Rules::NONE);

// Amount
check('amount mismatch', Rules::action('FILLED', '24.98', 25.0, false) === Rules::MISMATCH);
check('amount mismatch beats CANCELLED', Rules::action('CANCELLED', '30', 25.0, false) === Rules::MISMATCH);
check('amount within 0.01', Rules::action('FILLED', '24.99', 25.0, false) === Rules::PAID);
check('amount within 0.01 above', Rules::action('FILLED', '25.01', 25.0, false) === Rules::PAID);
check('amount 0.02 off', Rules::action('FILLED', '25.02', 25.0, false) === Rules::MISMATCH);
check('amount with trailing zeros', Rules::action('FILLED', '25.00000000', 25.0, false) === Rules::PAID);
check('no amount given', Rules::action('FILLED', '', 25.0, false) === Rules::PAID);

// URL rule, ids
check('https URL allowed', Rules::isAllowedBaseUrl('https://pay.example.com'));
check('http URL refused', !Rules::isAllowedBaseUrl('http://pay.example.com'));
check('http localhost allowed', Rules::isAllowedBaseUrl('http://localhost:8080'));
check('http 127.0.0.1 allowed', Rules::isAllowedBaseUrl('http://127.0.0.1:8080'));
check('http localhost.evil.com refused', !Rules::isAllowedBaseUrl('http://localhost.evil.com'));
check('ftp refused', !Rules::isAllowedBaseUrl('ftp://pay.example.com'));
check('credentials refused', !Rules::isAllowedBaseUrl('https://u:p@pay.example.com'));
check('empty URL allowed (not configured)', Rules::isAllowedBaseUrl(''));
check('customer id for a customer', Rules::customerId(7, 100) === 'mg-customer-7');
check('customer id for a guest', Rules::customerId(null, 100) === 'mg-order-100');
check('invoice id', Rules::invoiceId('000000123') === 'MG-000000123');
check('increment id from invoice', Rules::incrementIdFromInvoice('MG-000000123') === '000000123');
check('foreign invoice id', Rules::incrementIdFromInvoice('WC-123') === '');

// API client with a fake transport
function fakeTransport(array $responses, array &$calls): callable
{
    return function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$responses, &$calls) {
        $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true), 'timeout' => $timeout];
        return array_shift($responses);
    };
}
$req = ['customerID' => 'mg-order-1', 'customerEmail' => 'a@b.c', 'amountInUSD' => '25.00', 'invoiceID' => 'MG-1', 'returnURL' => 'https://shop.example.com/coldtill/payment/back/'];
$ok  = [200, '{"host":"https://pay.example.com","reference_id":"ref-1","url":"https://pay.example.com/pay/ref-1"}'];

$calls = [];
$res   = (new Client('https://pay.example.com/', 'key-1', fakeTransport([$ok], $calls)))->createPayment($req);
check('create: one call', count($calls) === 1);
check('create: POST to /api/v1/payment', $calls[0]['method'] === 'POST' && $calls[0]['url'] === 'https://pay.example.com/api/v1/payment');
check('create: API-Key header', $calls[0]['headers']['API-Key'] === 'key-1');
check('create: 20s timeout', $calls[0]['timeout'] === 20);
check('create: body sent as given', $calls[0]['body'] === $req);
check('create: returns reference and url', $res['reference_id'] === 'ref-1' && $res['url'] === 'https://pay.example.com/pay/ref-1');

$calls = [];
$res   = (new Client('https://pay.example.com', 'k', fakeTransport([[400, '{"error":"returnURL host is not allowed"}'], $ok], $calls)))->createPayment($req);
check('returnURL retry: two calls', count($calls) === 2);
check('returnURL retry: first with returnURL', isset($calls[0]['body']['returnURL']));
check('returnURL retry: second without returnURL', !isset($calls[1]['body']['returnURL']) && $calls[1]['body']['invoiceID'] === 'MG-1');
check('returnURL retry: succeeds', $res['reference_id'] === 'ref-1');

$calls = [];
try {
    (new Client('https://pay.example.com', 'k', fakeTransport([[400, '{"error":"returnURL host is not allowed"}'], [400, '{"error":"returnURL host is not allowed"}']], $calls)))->createPayment($req);
    check('returnURL retry: only once', false);
} catch (ApiException $e) {
    check('returnURL retry: only once', count($calls) === 2 && $e->getCode() === 400);
}

$calls = [];
try {
    (new Client('https://pay.example.com', 'k', fakeTransport([[400, '{"error":"amountInUSD must be between 0 and 10,000,000"}']], $calls)))->createPayment($req);
    check('other 400: no retry', false);
} catch (ApiException $e) {
    check('other 400: no retry', count($calls) === 1 && str_contains($e->getMessage(), 'amountInUSD'));
}

$calls = [];
$noReturn = $req;
unset($noReturn['returnURL']);
try {
    (new Client('https://pay.example.com', 'k', fakeTransport([[400, '{"error":"returnURL is invalid"}']], $calls)))->createPayment($noReturn);
    check('returnURL error without returnURL: no retry', false);
} catch (ApiException $e) {
    check('returnURL error without returnURL: no retry', count($calls) === 1);
}

$calls = [];
try {
    (new Client('https://pay.example.com', 'k', fakeTransport([[409, '{"error":"a deposit is still confirming"}']], $calls)))->createPayment($req);
    check('409: error with code', false);
} catch (ApiException $e) {
    check('409: error with code', $e->getCode() === 409 && $e->getMessage() === 'Coldtill: a deposit is still confirming');
}

$calls = [];
try {
    (new Client('https://pay.example.com', 'k', fakeTransport([[502, '<html>bad gateway</html>']], $calls)))->createPayment($req);
    check('non-JSON error', false);
} catch (ApiException $e) {
    check('non-JSON error', $e->getMessage() === 'Coldtill: HTTP 502');
}

$calls = [];
try {
    (new Client('https://pay.example.com', 'k', fakeTransport([[200, '{}']], $calls)))->createPayment($req);
    check('response without reference_id', false);
} catch (ApiException $e) {
    check('response without reference_id', true);
}

$calls = [];
$p = (new Client('https://pay.example.com', 'k', fakeTransport([[200, '{"paymentState":"FILLED","amountInUSD":"25","deposits":[{"transactionHash":"0xabc"}]}']], $calls)))->getPayment('ref/1');
check('get: GET /api/v1/payment/reference/{ref}', $calls[0]['method'] === 'GET' && $calls[0]['url'] === 'https://pay.example.com/api/v1/payment/reference/ref%2F1' && $calls[0]['body'] === null);
check('get: fields', $p['paymentState'] === 'FILLED' && $p['deposits'][0]['transactionHash'] === '0xabc');

try {
    (new Client('https://pay.example.com', 'k', function () {
        throw new ApiException('Coldtill is unreachable: timeout');
    }))->getPayment('ref-1');
    check('transport failure surfaces', false);
} catch (ApiException $e) {
    check('transport failure surfaces', $e->getCode() === 0);
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
