<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Gateway;

use Coinbase\PaymentLinkGateway\Model\Adminhtml\Source\Environment;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Payment\Gateway\Config\Config as GatewayConfig;

class Config extends GatewayConfig
{
    public const CODE = 'coinbase_payment_link';
    private const API_BASE_URL_PRODUCTION = 'https://business.coinbase.com';
    private const API_BASE_URL_SANDBOX = 'https://business.coinbase.com/sandbox';

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        ?string $methodCode = self::CODE,
        string $pathPattern = GatewayConfig::DEFAULT_PATH_PATTERN
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }

    public function isActive(?int $storeId = null): bool
    {
        return (bool) $this->getValue('active', $storeId);
    }

    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->getValue('title', $storeId);
    }

    public function getEnvironment(?int $storeId = null): string
    {
        return (string) $this->getValue('environment', $storeId);
    }

    public function isSandbox(?int $storeId = null): bool
    {
        return $this->getEnvironment($storeId) === Environment::SANDBOX;
    }

    public function getApiKeyName(?int $storeId = null): string
    {
        return (string) $this->getValue('api_key_name', $storeId);
    }

    public function getApiPrivateKey(?int $storeId = null): string
    {
        $value = (string) $this->getValue('api_private_key', $storeId);
        return $this->encryptor->decrypt($value);
    }

    public function getWebhookSecret(?int $storeId = null): string
    {
        $key = $this->isSandbox($storeId) ? 'sandbox_webhook_secret' : 'webhook_secret';
        $value = (string) $this->getValue($key, $storeId);
        return $this->encryptor->decrypt($value);
    }

    public function getExpirationHours(?int $storeId = null): int
    {
        return (int) ($this->getValue('expiration_hours', $storeId) ?: 24);
    }

    public function isDebugMode(?int $storeId = null): bool
    {
        return (bool) $this->getValue('debug', $storeId);
    }

    public function getApiBaseUrl(?int $storeId = null): string
    {
        return $this->isSandbox($storeId)
            ? self::API_BASE_URL_SANDBOX
            : self::API_BASE_URL_PRODUCTION;
    }

    public function getApiHostWithPath(?int $storeId = null): string
    {
        return str_replace(['https://', 'http://'], '', $this->getApiBaseUrl($storeId));
    }
}
