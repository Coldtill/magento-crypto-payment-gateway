<?php

declare(strict_types=1);

namespace Coldtill\Payment\Model\Api;

/** Coldtill merchant API client. No Magento dependencies; the HTTP transport can be swapped for tests. */
class Client
{
    public const TIMEOUT = 20;

    private string $baseUrl;

    /** @var callable(string,string,array<string,string>,?string,int):array{0:int,1:string} */
    private $transport;

    /**
     * @param callable|null $transport fn(method, url, headers, body, timeout): [status, body]; throws ApiException
     *                                 when the server can't be reached. Defaults to cURL.
     */
    public function __construct(string $baseUrl, private string $apiKey, ?callable $transport = null)
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    /** @return array{host?:string,reference_id:string,url:string} */
    public function createPayment(array $req): array
    {
        try {
            $res = $this->request('POST', '/payment', $req);
        } catch (ApiException $e) {
            // A project that limits return hosts rejects other stores; fall back to the project's Return URL.
            if (!isset($req['returnURL']) || $e->getCode() !== 400 || !str_contains($e->getMessage(), 'returnURL')) {
                throw $e;
            }
            unset($req['returnURL']);
            $res = $this->request('POST', '/payment', $req);
        }
        if (empty($res['reference_id']) || empty($res['url'])) {
            throw new ApiException('Coldtill: the payment response has no reference_id or url');
        }
        return $res;
    }

    public function getPayment(string $referenceId): array
    {
        return $this->request('GET', '/payment/reference/' . rawurlencode($referenceId));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = ['API-Key' => $this->apiKey, 'Accept' => 'application/json'];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        [$code, $raw] = ($this->transport)($method, $this->baseUrl . '/api/v1' . $path, $headers, $payload, self::TIMEOUT);
        $data = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) && isset($data['error']) && is_string($data['error']) ? $data['error'] : 'HTTP ' . $code;
            throw new ApiException('Coldtill: ' . $msg, (int) $code);
        }
        return is_array($data) ? $data : [];
    }

    /** @return array{0:int,1:string} */
    public static function curl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            throw new ApiException('Coldtill is unreachable: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [$code, (string) $raw];
    }
}
