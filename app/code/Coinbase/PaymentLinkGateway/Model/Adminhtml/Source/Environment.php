<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Model\Adminhtml\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Environment implements OptionSourceInterface
{
    public const PRODUCTION = 'production';
    public const SANDBOX = 'sandbox';

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::PRODUCTION, 'label' => __('Production')],
            ['value' => self::SANDBOX, 'label' => __('Sandbox')],
        ];
    }
}
