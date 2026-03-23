<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Gateway\Http;

use Coinbase\CheckoutGateway\Gateway\Config;
use Coinbase\CheckoutGateway\Service\JwtGenerator;
use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

class TransferFactory implements TransferFactoryInterface
{
    private const API_PATH = '/api/v1/checkouts';

    public function __construct(
        private readonly TransferBuilder $transferBuilder,
        private readonly JwtGenerator $jwtGenerator,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $request
     */
    public function create(array $request): TransferInterface
    {
        $storeId = isset($request['metadata']['storeId'])
            ? (int) $request['metadata']['storeId']
            : null;

        $jwt = $this->jwtGenerator->generate('POST', self::API_PATH, $storeId);
        $url = $this->config->getApiBaseUrl($storeId) . self::API_PATH;

        // Deterministic idempotency key from order ID
        $orderId = $request['metadata']['orderId'] ?? '';
        $idempotencyKey = $this->generateIdempotencyKey($orderId);

        return $this->transferBuilder
            ->setUri($url)
            ->setMethod('POST')
            ->setHeaders([
                'Authorization' => 'Bearer ' . $jwt,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Idempotency-Key' => $idempotencyKey,
            ])
            ->setBody($request)
            ->build();
    }

    private function generateIdempotencyKey(string $orderId): string
    {
        // Generate a deterministic UUID v4-like key from the order ID
        $hash = md5('coinbase_checkout_' . $orderId);

        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex(8 | (hexdec(substr($hash, 16, 1)) & 3)),
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
    }
}
