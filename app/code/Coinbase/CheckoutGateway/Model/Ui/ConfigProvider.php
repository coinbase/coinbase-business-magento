<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Model\Ui;

use Coinbase\CheckoutGateway\Gateway\Config;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;

class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
        private readonly AssetRepository $assetRepository
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        $isActive = $this->config->isActive();

        return [
            'payment' => [
                Config::CODE => [
                    'isActive' => $isActive,
                    'title' => $this->config->getTitle(),
                    'redirectUrl' => $this->urlBuilder->getUrl('coinbase/payment/create'),
                    'logoUrl' => $this->assetRepository->getUrl('Coinbase_CheckoutGateway::images/coinbase-logo.svg'),
                    'description' => __('Pay securely with USDC via Coinbase'),
                    'environment' => $this->config->getEnvironment(),
                ],
            ],
        ];
    }
}
