<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Service;

use Coinbase\PaymentLinkGateway\Gateway\Config;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class PaymentLinkService
{
    private const TIMEOUT = 30;

    public function __construct(
        private readonly Config $config,
        private readonly JwtGenerator $jwtGenerator,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get payment link status from the API.
     *
     * @return array<string, mixed>|null
     */
    public function getPaymentLinkStatus(string $paymentLinkId, ?int $storeId = null): ?array
    {
        $path = '/api/v1/payment-links/' . $paymentLinkId;

        try {
            $jwt = $this->jwtGenerator->generate('GET', $path, $storeId);
            $url = $this->config->getApiBaseUrl($storeId) . $path;

            $this->curl->setTimeout(self::TIMEOUT);
            $this->curl->addHeader('Authorization', 'Bearer ' . $jwt);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->get($url);

            $response = json_decode($this->curl->getBody(), true);

            if ($this->config->isDebugMode($storeId)) {
                $this->logger->debug('Coinbase GetPaymentLink Response', [
                    'payment_link_id' => $paymentLinkId,
                    'status' => $this->curl->getStatus(),
                ]);
            }

            return is_array($response) ? $response : null;
        } catch (\Throwable $e) {
            $this->logger->error('Coinbase GetPaymentLink Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Deactivate a payment link.
     *
     * @return array<string, mixed>|null
     */
    public function deactivatePaymentLink(string $paymentLinkId, ?int $storeId = null): ?array
    {
        $path = '/api/v1/payment-links/' . $paymentLinkId . '/deactivate';

        try {
            $jwt = $this->jwtGenerator->generate('POST', $path, $storeId);
            $url = $this->config->getApiBaseUrl($storeId) . $path;

            $this->curl->setTimeout(self::TIMEOUT);
            $this->curl->addHeader('Authorization', 'Bearer ' . $jwt);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->post($url, '');

            $response = json_decode($this->curl->getBody(), true);

            if ($this->config->isDebugMode($storeId)) {
                $this->logger->debug('Coinbase DeactivatePaymentLink Response', [
                    'payment_link_id' => $paymentLinkId,
                    'status' => $this->curl->getStatus(),
                ]);
            }

            return is_array($response) ? $response : null;
        } catch (\Throwable $e) {
            $this->logger->error('Coinbase DeactivatePaymentLink Error: ' . $e->getMessage());
            return null;
        }
    }
}
