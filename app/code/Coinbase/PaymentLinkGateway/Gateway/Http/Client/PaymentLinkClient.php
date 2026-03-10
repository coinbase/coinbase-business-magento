<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Gateway\Http\Client;

use Coinbase\PaymentLinkGateway\Gateway\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Psr\Log\LoggerInterface;

class PaymentLinkClient implements ClientInterface
{
    private const TIMEOUT = 30;

    public function __construct(
        private readonly Curl $curl,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param TransferInterface $transferObject
     * @return array<string, mixed>
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        $headers = $transferObject->getHeaders();
        $body = $transferObject->getBody();
        $url = $transferObject->getUri();

        $jsonBody = json_encode($body);

        if ($this->config->isDebugMode()) {
            $this->logger->debug('Coinbase API Request', [
                'url' => $url,
                'body' => $this->redactSensitiveData($body),
            ]);
        }

        try {
            $this->curl->setTimeout(self::TIMEOUT);

            foreach ($headers as $name => $value) {
                $this->curl->addHeader($name, $value);
            }

            $this->curl->post($url, $jsonBody);

            $statusCode = $this->curl->getStatus();
            $responseBody = $this->curl->getBody();
            $response = json_decode($responseBody, true) ?: [];

            if ($this->config->isDebugMode()) {
                $this->logger->debug('Coinbase API Response', [
                    'status' => $statusCode,
                    'body' => $this->redactSensitiveData($response),
                ]);
            }

            $response['http_status_code'] = $statusCode;

            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('Coinbase API Error: ' . $e->getMessage());
            throw new \Magento\Payment\Gateway\Http\ClientException(
                __('Unable to communicate with Coinbase payment service.')
            );
        }
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private function redactSensitiveData(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitiveKeys = ['Authorization', 'api_private_key', 'webhook_secret'];
        foreach ($sensitiveKeys as $key) {
            if (isset($data[$key])) {
                $data[$key] = '***REDACTED***';
            }
        }

        return $data;
    }
}
