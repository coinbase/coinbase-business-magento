<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Controller\Payment;

use Coinbase\CheckoutGateway\Gateway\Config;
use Coinbase\CheckoutGateway\Service\WebhookSignatureValidator;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

class Webhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly WebhookSignatureValidator $signatureValidator,
        private readonly OrderFactory $orderFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly OrderSender $orderSender,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Skip CSRF validation for webhook endpoint.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Skip CSRF validation for webhook endpoint.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $rawBody = $this->request->getContent();
            $signatureHeader = $this->request->getHeader('X-Hook0-Signature');

            if (empty($signatureHeader)) {
                $payload = json_decode($rawBody, true);
                if (empty($payload) || empty($payload['eventType'])) {
                    return $result->setData(['status' => 'ok']);
                }
                $this->logger->error('Coinbase webhook: Missing X-Hook0-Signature header');
                return $result->setHttpResponseCode(400)->setData(['error' => 'Missing signature']);
            }

            // Collect headers for signature validation
            $headers = $this->getRequestHeaders();

            if (!$this->signatureValidator->validate($rawBody, $signatureHeader, $headers)) {
                return $result->setHttpResponseCode(400)->setData(['error' => 'Invalid signature']);
            }

            $payload = json_decode($rawBody, true);
            if (!is_array($payload)) {
                return $result->setHttpResponseCode(400)->setData(['error' => 'Invalid payload']);
            }

            $eventType = $payload['eventType'] ?? '';
            $orderId = $payload['metadata']['orderId'] ?? '';

            if (empty($orderId)) {
                $this->logger->error('Coinbase webhook: Missing orderId in metadata');
                return $result->setHttpResponseCode(400)->setData(['error' => 'Missing orderId']);
            }

            if ($this->config->isDebugMode()) {
                $this->logger->debug('Coinbase webhook received', [
                    'event_type' => $eventType,
                    'order_id' => $orderId,
                    'checkout_id' => $payload['id'] ?? '',
                ]);
            }

            $order = $this->orderFactory->create()->loadByIncrementId($orderId);

            if (!$order->getId()) {
                $this->logger->error('Coinbase webhook: Order not found - ' . $orderId);
                return $result->setHttpResponseCode(200)->setData(['status' => 'order_not_found']);
            }

            // Idempotency: if order is already in final state, acknowledge and return
            $state = $order->getState();
            if (in_array($state, [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED])) {
                return $result->setData(['status' => 'already_processed']);
            }

            match ($eventType) {
                'checkout.payment.success' => $this->handlePaymentSuccess($order, $payload),
                'checkout.payment.failed' => $this->handlePaymentFailed($order, $payload),
                'checkout.payment.expired' => $this->handlePaymentExpired($order, $payload),
                default => $this->logger->warning('Coinbase webhook: Unknown event type - ' . $eventType),
            };

            return $result->setData(['status' => 'ok']);
        } catch (\Throwable $e) {
            $this->logger->error('Coinbase webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $result->setHttpResponseCode(500)->setData(['error' => 'Internal error']);
        }
    }

    /**
     * Reconcile the settled payment against the order before invoicing.
     *
     * A valid signature only proves Coinbase sent the event; it does not prove the
     * customer paid what the order is worth. The checkout is created for the base
     * grand total as USDC (1:1 with USD), so require a USD base currency and a
     * settled USDC amount that covers the base grand total. Anything else places
     * the order on hold for manual review instead of invoicing it.
     *
     * @param array<string, mixed> $payload
     */
    private function handlePaymentSuccess(Order $order, array $payload): void
    {
        $payment = $order->getPayment();

        $mismatch = $this->findPaymentMismatch($order, $payload);
        if ($mismatch !== null) {
            $this->holdUnreconciledPayment($order, $payload, $mismatch);
            return;
        }

        // Update payment additional info
        $payment->setAdditionalInformation('coinbase_checkout_status', $payload['status'] ?? 'COMPLETED');

        if (!empty($payload['transactionHash'])) {
            $payment->setAdditionalInformation('coinbase_transaction_hash', $payload['transactionHash']);
            $payment->setTransactionId($payload['transactionHash']);
        }

        if (!empty($payload['settlement'])) {
            $payment->setAdditionalInformation('coinbase_settlement', $payload['settlement']);
        }

        $payment->setIsTransactionPending(false);
        $payment->setIsTransactionClosed(true);

        // Create invoice
        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
            $invoice->register();

            $dbTransaction = $this->transactionFactory->create();
            $dbTransaction->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();
        }

        // Update order state
        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));

        $txHash = $payload['transactionHash'] ?? 'N/A';
        $order->addCommentToStatusHistory(
            __('Coinbase payment confirmed. Transaction: %1', $txHash)
        );

        $this->orderRepository->save($order);

        // Send order confirmation email
        try {
            $this->orderSender->send($order);
        } catch (\Throwable $e) {
            $this->logger->error('Coinbase webhook: Failed to send order email - ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handlePaymentFailed(Order $order, array $payload): void
    {
        if ($order->getState() === Order::STATE_CANCELED) {
            return;
        }

        $payment = $order->getPayment();
        $payment->setAdditionalInformation('coinbase_checkout_status', $payload['status'] ?? 'FAILED');

        $order->addCommentToStatusHistory(__('Coinbase payment failed.'));
        $this->orderRepository->save($order);
        $this->orderManagement->cancel($order->getEntityId());
    }

    /**
     * Returns a reason string when the settled payment does not cover the order, or null when it reconciles.
     *
     * @param array<string, mixed> $payload
     */
    private function findPaymentMismatch(Order $order, array $payload): ?string
    {
        $baseCurrency = (string) $order->getBaseCurrencyCode();
        if (!Config::isSupportedBaseCurrency($baseCurrency)) {
            return sprintf(
                'order base currency %s is not %s',
                $baseCurrency !== '' ? $baseCurrency : '(none)',
                Config::SUPPORTED_BASE_CURRENCY
            );
        }

        $settlement = is_array($payload['settlement'] ?? null) ? $payload['settlement'] : [];
        $paidAmount = $settlement['totalAmount'] ?? $payload['amount'] ?? null;
        $paidCurrency = $settlement['currency'] ?? $payload['currency'] ?? null;

        if (!is_numeric($paidAmount)) {
            return 'settled amount missing from webhook payload';
        }

        if (!is_string($paidCurrency) || strtoupper(trim($paidCurrency)) !== Config::SETTLEMENT_CURRENCY) {
            return sprintf(
                'settled currency %s is not %s',
                is_string($paidCurrency) && $paidCurrency !== '' ? $paidCurrency : '(none)',
                Config::SETTLEMENT_CURRENCY
            );
        }

        // Compare in integer cents to avoid float drift; the checkout amount was sent with 2 decimals.
        $expectedCents = (int) round((float) $order->getBaseGrandTotal() * 100);
        $paidCents = (int) round((float) $paidAmount * 100);

        if ($paidCents < $expectedCents) {
            return sprintf(
                'settled amount %s %s is less than order total %s %s',
                number_format($paidCents / 100, 2, '.', ''),
                Config::SETTLEMENT_CURRENCY,
                number_format($expectedCents / 100, 2, '.', ''),
                $baseCurrency
            );
        }

        return null;
    }

    /**
     * Record the settled payment without invoicing, and hold the order for manual review.
     *
     * @param array<string, mixed> $payload
     */
    private function holdUnreconciledPayment(Order $order, array $payload, string $reason): void
    {
        $payment = $order->getPayment();

        // Webhook retries: already flagged, nothing more to record.
        if ($payment->getAdditionalInformation('coinbase_reconciliation_error')) {
            return;
        }

        $txHash = $payload['transactionHash'] ?? 'N/A';

        $this->logger->error('Coinbase webhook: Payment not reconciled, order held - ' . $reason, [
            'order_id' => $order->getIncrementId(),
            'checkout_id' => $payload['id'] ?? '',
            'transaction_hash' => $txHash,
        ]);

        $payment->setAdditionalInformation('coinbase_checkout_status', $payload['status'] ?? 'COMPLETED');
        $payment->setAdditionalInformation('coinbase_reconciliation_error', $reason);
        if (!empty($payload['transactionHash'])) {
            $payment->setAdditionalInformation('coinbase_transaction_hash', $payload['transactionHash']);
        }
        if (!empty($payload['settlement'])) {
            $payment->setAdditionalInformation('coinbase_settlement', $payload['settlement']);
        }

        $order->addCommentToStatusHistory(
            __(
                'Coinbase payment received but NOT reconciled (%1). Order placed on hold for manual review; do not fulfill. Transaction: %2',
                $reason,
                $txHash
            )
        );

        if ($order->canHold()) {
            $order->hold();
        }

        $this->orderRepository->save($order);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handlePaymentExpired(Order $order, array $payload): void
    {
        if ($order->getState() === Order::STATE_CANCELED) {
            return;
        }

        $payment = $order->getPayment();
        $payment->setAdditionalInformation('coinbase_checkout_status', $payload['status'] ?? 'EXPIRED');

        $order->addCommentToStatusHistory(__('Coinbase checkout expired.'));
        $this->orderRepository->save($order);
        $this->orderManagement->cancel($order->getEntityId());
    }

    /**
     * @return array<string, string>
     */
    private function getRequestHeaders(): array
    {
        $headers = [];

        if (method_exists($this->request, 'getHeaders')) {
            /** @var \Laminas\Http\Headers $headerBag */
            $headerBag = $this->request->getHeaders();
            if ($headerBag) {
                foreach ($headerBag as $header) {
                    $headers[strtolower($header->getFieldName())] = $header->getFieldValue();
                }
            }
        }

        // Fallback: populate from $_SERVER
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }

        return $headers;
    }
}
