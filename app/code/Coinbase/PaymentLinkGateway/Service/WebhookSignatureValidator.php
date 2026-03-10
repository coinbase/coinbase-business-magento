<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Service;

use Coinbase\PaymentLinkGateway\Gateway\Config;
use Psr\Log\LoggerInterface;

class WebhookSignatureValidator
{
    private const MAX_AGE_MINUTES = 5;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Verify the webhook signature from X-Hook0-Signature header.
     *
     * Header format: t=<timestamp>,h=<header_names>,v1=<signature>
     *
     * @param string $payload Raw request body
     * @param string $signatureHeader X-Hook0-Signature header value
     * @param array<string, string> $headers All HTTP request headers (lowercase keys)
     */
    public function validate(string $payload, string $signatureHeader, array $headers, ?int $storeId = null): bool
    {
        try {
            $secret = $this->config->getWebhookSecret($storeId);
            if (empty($secret)) {
                $this->logger->error('Coinbase webhook: Webhook secret is not configured');
                return false;
            }

            $elements = explode(',', $signatureHeader);
            $parsed = [];
            foreach ($elements as $element) {
                $parts = explode('=', $element, 2);
                if (count($parts) === 2) {
                    $parsed[$parts[0]] = $parts[1];
                }
            }

            if (!isset($parsed['t'], $parsed['h'], $parsed['v1'])) {
                $this->logger->error('Coinbase webhook: Missing required signature fields (t, h, v1)');
                return false;
            }

            $timestamp = $parsed['t'];
            $headerNames = $parsed['h'];
            $providedSignature = $parsed['v1'];

            // Build header values string
            $headerNameList = explode(' ', $headerNames);
            $headerValues = implode('.', array_map(
                fn(string $name): string => $headers[strtolower($name)] ?? '',
                $headerNameList
            ));

            // Build signed payload: timestamp.headerNames.headerValues.body
            $signedPayload = "{$timestamp}.{$headerNames}.{$headerValues}.{$payload}";

            // Compute expected signature
            $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

            // Timing-safe comparison
            if (!hash_equals($expectedSignature, $providedSignature)) {
                $this->logger->error('Coinbase webhook: Signature mismatch');
                return false;
            }

            // Check timestamp for replay protection
            $webhookTime = (int) $timestamp;
            $currentTime = time();
            $ageMinutes = ($currentTime - $webhookTime) / 60;

            if ($ageMinutes > self::MAX_AGE_MINUTES) {
                $this->logger->error(
                    sprintf('Coinbase webhook: Timestamp too old (%.1f minutes > %d minutes)', $ageMinutes, self::MAX_AGE_MINUTES)
                );
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Coinbase webhook: Verification error - ' . $e->getMessage());
            return false;
        }
    }
}
