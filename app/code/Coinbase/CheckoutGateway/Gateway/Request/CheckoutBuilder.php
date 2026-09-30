<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Gateway\Request;

use Coinbase\CheckoutGateway\Gateway\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;

class CheckoutBuilder implements BuilderInterface
{
    /**
     * Build the core checkout request data: amount, currency, network, description.
     *
     * The amount sent to Coinbase is the order's base grand total, denominated as
     * USDC (1:1 with USD). The order's base currency must therefore be USD; any
     * other base currency would be forwarded numerically as USDC and misprice the
     * order, so we fail closed here even if the method was somehow selectable.
     *
     * @param array<string, mixed> $buildSubject
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function build(array $buildSubject): array
    {
        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $buildSubject['payment'];
        $order = $paymentDO->getOrder();

        // OrderAdapter::getCurrencyCode() is the base currency code, matching
        // getGrandTotalAmount() which returns the base grand total.
        $baseCurrency = $order->getCurrencyCode();
        if (!Config::isSupportedBaseCurrency($baseCurrency)) {
            throw new LocalizedException(
                __(
                    'Coinbase Business is only available for stores with a %1 base currency (order currency: %2).',
                    Config::SUPPORTED_BASE_CURRENCY,
                    (string) $baseCurrency
                )
            );
        }

        $amount = number_format((float) $order->getGrandTotalAmount(), 2, '.', '');

        return [
            'amount' => $amount,
            'currency' => Config::SETTLEMENT_CURRENCY,
            'network' => 'base',
            'description' => sprintf('Payment for order #%s', $order->getOrderIncrementId()),
        ];
    }
}
