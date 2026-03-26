<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Controller\Payment;

use Coinbase\CheckoutGateway\Gateway\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

class Create implements HttpPostActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly JsonFactory $jsonFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $order = $this->checkoutSession->getLastRealOrder();

            if (!$order || !$order->getId()) {
                return $result->setData([
                    'success' => false,
                    'message' => 'No order found.',
                ]);
            }

            if ($order->getPayment()->getMethod() !== Config::CODE) {
                return $result->setData([
                    'success' => false,
                    'message' => 'Invalid payment method.',
                ]);
            }

            $redirectUrl = $order->getPayment()->getAdditionalInformation('coinbase_checkout_url');

            if (empty($redirectUrl)) {
                return $result->setData([
                    'success' => false,
                    'message' => 'Checkout URL not found. Please try again.',
                ]);
            }

            return $result->setData([
                'success' => true,
                'redirect_url' => $redirectUrl,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Coinbase Create Controller Error: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => 'An error occurred while processing your payment.',
            ]);
        }
    }
}
