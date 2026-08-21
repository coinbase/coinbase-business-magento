<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Controller\Payment;

use Coinbase\CheckoutGateway\Service\RedirectHash;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

class ReturnAction implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly OrderFactory $orderFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectHash $redirectHash,
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

            if (!$order->getId() || !$this->redirectHash->isValid($orderId, (int) $order->getStoreId(), $hash)) {
                $this->messageManager->addErrorMessage(__('Invalid return URL.'));
                return $this->redirectToCart();
            }

            $state = $order->getState();

            if ($state === Order::STATE_PROCESSING || $state === Order::STATE_COMPLETE) {
                // Payment confirmed via webhook
                $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
                $this->checkoutSession->setLastQuoteId($order->getQuoteId());
                $this->checkoutSession->setLastOrderId($order->getId());
                $this->checkoutSession->setLastRealOrderId($order->getIncrementId());

                return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
                    ->setPath('checkout/onepage/success');
            }

            if ($state === Order::STATE_PENDING_PAYMENT || $state === Order::STATE_NEW) {
                // Webhook hasn't arrived yet — show success page, webhook will confirm later
                $this->messageManager->addSuccessMessage(
                    __('Your payment is being confirmed. You will receive an email once it is complete.')
                );
                $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
                $this->checkoutSession->setLastQuoteId($order->getQuoteId());
                $this->checkoutSession->setLastOrderId($order->getId());
                $this->checkoutSession->setLastRealOrderId($order->getIncrementId());

                return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
                    ->setPath('checkout/onepage/success');
            }

            // Order was canceled or in unexpected state
            $this->messageManager->addErrorMessage(__('Your payment could not be processed.'));
            return $this->redirectToCart();
        } catch (\Throwable $e) {
            $this->logger->error('Coinbase ReturnAction Error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('An error occurred while processing your payment return.'));
            return $this->redirectToCart();
        }
    }

    private function redirectToCart(): ResultInterface
    {
        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('checkout/cart');
    }
}
