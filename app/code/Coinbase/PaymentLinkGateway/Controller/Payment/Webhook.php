<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Controller\Payment;

use Coinbase\PaymentLinkGateway\Gateway\Config;
use Coinbase\PaymentLinkGateway\Service\WebhookSignatureValidator;
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
                    'payment_link_id' => $payload['id'] ?? '',
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
                'payment_link.payment.success' => $this->handlePaymentSuccess($order, $payload),
                'payment_link.payment.failed' => $this->handlePaymentFailed($order, $payload),
                'payment_link.payment.expired' => $this->handlePaymentExpired($order, $payload),
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
     * @param array<string, mixed> $payload
     */
    private function handlePaymentSuccess(Order $order, array $payload): void
    {
        $payment = $order->getPayment();

        // Update payment additional info
        $payment->setAdditionalInformation('coinbase_payment_link_status', $payload['status'] ?? 'COMPLETED');

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
        $payment->setAdditionalInformation('coinbase_payment_link_status', $payload['status'] ?? 'FAILED');

        $order->addCommentToStatusHistory(__('Coinbase payment failed.'));
        $this->orderRepository->save($order);
        $this->orderManagement->cancel($order->getEntityId());
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
        $payment->setAdditionalInformation('coinbase_payment_link_status', $payload['status'] ?? 'EXPIRED');

        $order->addCommentToStatusHistory(__('Coinbase payment link expired.'));
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
