<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Service;

use Coinbase\CheckoutGateway\Gateway\Config;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class CheckoutService
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
     * Get checkout status from the API.
     *
     * @return array<string, mixed>|null
     */
    public function getCheckoutStatus(string $checkoutId, ?int $storeId = null): ?array
    {
        $path = '/api/v1/checkouts/' . $checkoutId;

        try {
            $jwt = $this->jwtGenerator->generate('GET', $path, $storeId);
            $url = $this->config->getApiBaseUrl($storeId) . $path;

            $this->curl->setTimeout(self::TIMEOUT);
            $this->curl->addHeader('Authorization', 'Bearer ' . $jwt);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->get($url);

            $response = json_decode($this->curl->getBody(), true);

            if ($this->config->isDebugMode($storeId)) {
                $this->logger->debug('Coinbase GetCheckout Response', [
                    'checkout_id' => $checkoutId,
                    'status' => $this->curl->getStatus(),
                ]);
            }

            return is_array($response) ? $response : null;
        } catch (\Throwable $e) {
            $this->logger->error('Coinbase GetCheckout Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Deactivate a checkout.
     *
     * @return array<string, mixed>|null
     */
    public function deactivateCheckout(string $checkoutId, ?int $storeId = null): ?array
    {
        $path = '/api/v1/checkouts/' . $checkoutId . '/deactivate';

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
                $this->logger->debug('Coinbase DeactivateCheckout Response', [
                    'checkout_id' => $checkoutId,
                    'status' => $this->curl->getStatus(),
                ]);
            }

            return is_array($response) ? $response : null;
        } catch (\Throwable $e) {
            $this->logger->error('Coinbase DeactivateCheckout Error: ' . $e->getMessage());
            return null;
        }
    }
}
