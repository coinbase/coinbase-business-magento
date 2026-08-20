<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Service;

use Coinbase\CheckoutGateway\Gateway\Config;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class RedirectHash
{
    private const HASH_ALGO = 'sha256';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Generate an HMAC for redirect URL tamper protection.
     *
     * @throws LocalizedException if the webhook secret is not configured
     */
    public function generate(string $orderId, int $storeId): string
    {
        $secret = $this->getRequiredSecret($storeId);
        if ($secret === null) {
            throw new LocalizedException(
                __('Unable to initialize Coinbase payment. Please contact the store owner or try another payment method.')
            );
        }

        return hash_hmac(self::HASH_ALGO, $this->payload($orderId, $storeId), $secret);
    }

    /**
     * Validate a redirect HMAC. Fails closed when the secret is unset.
     */
    public function isValid(string $orderId, int $storeId, ?string $hash): bool
    {
        if (empty($hash)) {
            return false;
        }

        $secret = $this->getRequiredSecret($storeId);
        if ($secret === null) {
            return false;
        }

        $expected = hash_hmac(self::HASH_ALGO, $this->payload($orderId, $storeId), $secret);

        return hash_equals($expected, $hash);
    }

    private function getRequiredSecret(?int $storeId): ?string
    {
        $secret = $this->config->getWebhookSecret($storeId);
        if (empty($secret)) {
            $this->logger->error('Coinbase redirect HMAC: Webhook secret is not configured');
            return null;
        }

        return $secret;
    }

    private function payload(string $orderId, int $storeId): string
    {
        return $orderId . '|' . $storeId;
    }
}
