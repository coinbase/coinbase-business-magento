<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Gateway\Response;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;

class PaymentLinkHandler implements HandlerInterface
{
    /**
     * Store payment link response data on the payment's additional information.
     *
     * @param array<string, mixed> $handlingSubject
     * @param array<string, mixed> $response
     */
    public function handle(array $handlingSubject, array $response): void
    {
        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $handlingSubject['payment'];

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();

        if (isset($response['id'])) {
            $payment->setAdditionalInformation('coinbase_payment_link_id', $response['id']);
        }

        if (isset($response['url'])) {
            $payment->setAdditionalInformation('coinbase_payment_link_url', $response['url']);
        }

        if (isset($response['status'])) {
            $payment->setAdditionalInformation('coinbase_payment_link_status', $response['status']);
        }

        if (isset($response['address'])) {
            $payment->setAdditionalInformation('coinbase_payment_link_address', $response['address']);
        }

        if (isset($response['expiresAt'])) {
            $payment->setAdditionalInformation('coinbase_payment_link_expires_at', $response['expiresAt']);
        }

        $payment->setIsTransactionPending(true);
        $payment->setIsTransactionClosed(false);

        // Set order state to pending_payment via stateObject (required for "initialize" payment action)
        if (isset($handlingSubject['stateObject'])) {
            $stateObject = $handlingSubject['stateObject'];
            $stateObject->setState(Order::STATE_PENDING_PAYMENT);
            $stateObject->setStatus('pending_payment');
            $stateObject->setIsNotified(false);
        }
    }
}
