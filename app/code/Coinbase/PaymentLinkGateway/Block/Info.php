<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Block;

use Magento\Payment\Block\ConfigurableInfo;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Gateway\ConfigInterface;

class Info extends ConfigurableInfo
{
    private const BASESCAN_TX_URL = 'https://basescan.org/tx/';

    /**
     * @param string $field
     * @return string|\Magento\Framework\Phrase
     */
    protected function getLabel($field)
    {
        $labels = [
            'coinbase_payment_link_id' => __('Payment Link ID'),
            'coinbase_payment_link_url' => __('Payment Link URL'),
            'coinbase_payment_link_status' => __('Payment Status'),
            'coinbase_payment_link_address' => __('Payment Address'),
            'coinbase_payment_link_expires_at' => __('Expires At'),
            'coinbase_transaction_hash' => __('Transaction Hash'),
            'coinbase_settlement' => __('Settlement'),
        ];

        return $labels[$field] ?? $field;
    }

    /**
     * @param string $field
     * @param mixed $value
     * @return mixed
     */
    protected function getValueView($field, $value)
    {
        if ($field === 'coinbase_transaction_hash' && !empty($value)) {
            $shortHash = substr($value, 0, 10) . '...' . substr($value, -8);
            return sprintf(
                '<a href="%s%s" target="_blank" rel="noopener noreferrer">%s</a>',
                self::BASESCAN_TX_URL,
                htmlspecialchars($value, ENT_QUOTES),
                htmlspecialchars($shortHash, ENT_QUOTES)
            );
        }

        if ($field === 'coinbase_settlement' && is_array($value)) {
            return sprintf(
                'Total: %s USDC | Fee: %s USDC | Net: %s USDC',
                $value['totalAmount'] ?? 'N/A',
                $value['feeAmount'] ?? 'N/A',
                $value['netAmount'] ?? 'N/A'
            );
        }

        return parent::getValueView($field, $value);
    }

    /**
     * @return string
     */
    protected function _toHtml()
    {
        $html = parent::_toHtml();

        $html .= '<p style="margin-top: 8px; font-size: 12px; color: #999;">'
            . __('Refunds are not supported through this payment method. Please contact the merchant directly.')
            . '</p>';

        return $html;
    }
}
