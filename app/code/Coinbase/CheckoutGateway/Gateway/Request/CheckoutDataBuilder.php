<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Gateway\Request;

use Coinbase\CheckoutGateway\Gateway\Config;
use Coinbase\CheckoutGateway\Service\RedirectHash;
use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;

class CheckoutDataBuilder implements BuilderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly RedirectHash $redirectHash,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * Build redirect URLs, metadata, and expiry.
     *
     * @param array<string, mixed> $buildSubject
     * @return array<string, mixed>
     */
    public function build(array $buildSubject): array
    {
        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $buildSubject['payment'];
        $order = $paymentDO->getOrder();
        $payment = $paymentDO->getPayment();

        $orderId = $order->getOrderIncrementId();
        $storeId = (int) $order->getStoreId();
        $quoteId = $payment->getAdditionalInformation('quote_id') ?? '';

        $hash = $this->redirectHash->generate($orderId, $storeId);

        $successUrl = $this->urlBuilder->getUrl('coinbase/payment/return', [
            'order_id' => $orderId,
            'hash' => $hash,
        ]);

        $failUrl = $this->urlBuilder->getUrl('coinbase/payment/cancel', [
            'order_id' => $orderId,
            'hash' => $hash,
        ]);

        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $this->config->getExpirationHours($storeId) . ' hours')
            ->format('Y-m-d\TH:i:s\Z');

        $result = [
            'expiresAt' => $expiresAt,
            'successRedirectUrl' => $successUrl,
            'failRedirectUrl' => $failUrl,
            'metadata' => [
                'orderId' => $orderId,
                'storeId' => (string) $storeId,
            ],
        ];

        if (!empty($quoteId)) {
            $result['metadata']['quoteId'] = (string) $quoteId;
        }

        return $result;
    }
}
