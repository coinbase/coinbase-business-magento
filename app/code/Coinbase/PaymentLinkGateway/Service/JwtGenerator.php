<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Service;

use Coinbase\PaymentLinkGateway\Gateway\Config;
use Firebase\JWT\JWT;
use Magento\Framework\Exception\LocalizedException;

class JwtGenerator
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Generate an ECDSA (ES256) JWT for Coinbase CDP API authentication.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path API path (e.g., /api/v1/payment-links)
     * @throws LocalizedException
     */
    public function generate(string $method, string $path, ?int $storeId = null): string
    {
        $keyName = $this->config->getApiKeyName($storeId);
        $privateKeyPem = $this->config->getApiPrivateKey($storeId);
        $host = $this->config->getApiHostWithPath($storeId);

        if (empty($keyName)) {
            throw new LocalizedException(
                __('Coinbase payment is not configured: API Key Name is missing. Please check the payment method settings.')
            );
        }

        if (empty($privateKeyPem)) {
            throw new LocalizedException(
                __('Coinbase payment is not configured: API Private Key is missing. Please check the payment method settings.')
            );
        }

        $uri = strtoupper($method) . ' ' . $host . $path;

        // Normalize literal \n sequences to real newlines (common when key is pasted into admin config)
        $privateKeyPem = str_replace('\\n', "\n", $privateKeyPem);

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKey) {
            throw new LocalizedException(
                __('Coinbase payment configuration error: the API Private Key is not a valid EC private key.')
            );
        }

        $time = time();
        $nonce = bin2hex(random_bytes(16));

        $payload = [
            'sub' => $keyName,
            'iss' => 'cdp',
            'nbf' => $time,
            'exp' => $time + 120,
            'uri' => $uri,
        ];

        $headers = [
            'typ' => 'JWT',
            'alg' => 'ES256',
            'kid' => $keyName,
            'nonce' => $nonce,
        ];

        return JWT::encode($payload, $privateKey, 'ES256', $keyName, $headers);
    }
}
