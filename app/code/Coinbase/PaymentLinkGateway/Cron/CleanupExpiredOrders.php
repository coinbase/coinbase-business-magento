<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Cron;

use Coinbase\PaymentLinkGateway\Gateway\Config;
use Coinbase\PaymentLinkGateway\Service\PaymentLinkService;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;

class CleanupExpiredOrders
{
    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly PaymentLinkService $paymentLinkService,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isActive()) {
            return;
        }

        $expirationHours = $this->config->getExpirationHours();

        // Find orders that are still pending_payment and older than expiration window
        $cutoffTime = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . $expirationHours . ' hours')
            ->format('Y-m-d H:i:s');

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('state', Order::STATE_PENDING_PAYMENT);
        $collection->addFieldToFilter('created_at', ['lt' => $cutoffTime]);

        // Join payment method
        $collection->getSelect()->join(
            ['payment' => $collection->getTable('sales_order_payment')],
            'main_table.entity_id = payment.parent_id',
            []
        );
        $collection->getSelect()->where('payment.method = ?', Config::CODE);

        $collection->setPageSize(50);

        foreach ($collection as $order) {
            try {
                $paymentLinkId = $order->getPayment()
                    ->getAdditionalInformation('coinbase_payment_link_id');

                // Check actual status from Coinbase API before canceling
                if (!empty($paymentLinkId)) {
                    $status = $this->paymentLinkService->getPaymentLinkStatus(
                        $paymentLinkId,
                        (int) $order->getStoreId()
                    );

                    // If the API says it's completed, don't cancel
                    if ($status && in_array($status['status'] ?? '', ['COMPLETED', 'PROCESSING'])) {
                        $this->logger->info(sprintf(
                            'Coinbase cleanup: Order %s has payment status %s, skipping cancellation',
                            $order->getIncrementId(),
                            $status['status']
                        ));
                        continue;
                    }
                }

                $order->addCommentToStatusHistory(
                    __('Order automatically canceled: Coinbase payment link expired.')
                );
                $this->orderRepository->save($order);
                $this->orderManagement->cancel($order->getEntityId());

                $this->logger->info(sprintf(
                    'Coinbase cleanup: Canceled expired order %s',
                    $order->getIncrementId()
                ));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Coinbase cleanup: Failed to cancel order %s - %s',
                    $order->getIncrementId(),
                    $e->getMessage()
                ));
            }
        }
    }
}
