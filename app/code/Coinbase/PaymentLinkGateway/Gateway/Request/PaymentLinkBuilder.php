<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Gateway\Request;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;

class PaymentLinkBuilder implements BuilderInterface
{
    /**
     * Build the core payment link request data: amount, currency, network, description.
     *
     * @param array<string, mixed> $buildSubject
     * @return array<string, mixed>
     */
    public function build(array $buildSubject): array
    {
        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $buildSubject['payment'];
        $order = $paymentDO->getOrder();

        $amount = number_format((float) $order->getGrandTotalAmount(), 2, '.', '');

        return [
            'amount' => $amount,
            'currency' => 'USDC',
            'network' => 'base',
            'description' => sprintf('Payment for order #%s', $order->getOrderIncrementId()),
        ];
    }
}
