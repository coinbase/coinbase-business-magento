<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Controller\Payment;

use Coinbase\CheckoutGateway\Gateway\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

class Cancel implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly OrderFactory $orderFactory,
        private readonly OrderManagementInterface $orderManagement,
        private readonly CheckoutSession $checkoutSession,
        private readonly Config $config,
        private readonly MessageManager $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $orderId = $this->request->getParam('order_id');
        $hash = $this->request->getParam('hash');

        try {
            $order = $this->orderFactory->create()->loadByIncrementId($orderId);

            if (!$order->getId() || !$this->validateHash($orderId, (int) $order->getStoreId(), $hash)) {
                $this->messageManager->addErrorMessage(__('Invalid cancel URL.'));
                return $this->redirectToCart();
            }

            // Only cancel if still in pending_payment state
            if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
                $order->addCommentToStatusHistory(
                    __('Payment canceled by customer on Coinbase payment page.')
                );
                $this->orderManagement->cancel($order->getEntityId());

                // Restore the quote so the cart is repopulated
                $this->restoreQuote($order);
            }

            $this->messageManager->addErrorMessage(
                __('Your Coinbase payment was canceled. Your cart has been restored.')
            );
        } catch (\Throwable $e) {
            $this->logger->error('Coinbase Cancel Controller Error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('An error occurred while canceling your payment.'));
        }

        return $this->redirectToCart();
    }

    private function validateHash(string $orderId, int $storeId, ?string $hash): bool
    {
        if (empty($hash)) {
            return false;
        }

        $secret = $this->config->getWebhookSecret($storeId);
        $expected = hash_hmac('sha256', $orderId . '|' . $storeId, $secret);

        return hash_equals($expected, $hash);
    }

    private function restoreQuote(Order $order): void
    {
        $quoteId = $order->getQuoteId();
        if ($quoteId) {
            $this->checkoutSession->restoreQuote();
        }
    }

    private function redirectToCart(): ResultInterface
    {
        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('checkout/cart');
    }
}
