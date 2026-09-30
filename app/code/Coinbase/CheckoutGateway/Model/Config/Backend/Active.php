<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Model\Config\Backend;

use Coinbase\CheckoutGateway\Gateway\Config;
use Coinbase\CheckoutGateway\Model\Adminhtml\Source\Environment;
use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class Active extends Value
{
    /**
     * Validate prerequisites only when enabling the payment method so admins
     * can still disable it (or save other settings) with a blank secret:
     *  - the base currency at the saved scope must be USD (checkouts are created
     *    for the base grand total as USDC, 1:1 with USD);
     *  - a webhook secret must be set for the selected environment.
     */
    public function beforeSave()
    {
        if (!(int) $this->getValue()) {
            return parent::beforeSave();
        }

        $baseCurrency = (string) $this->_config->getValue(
            Currency::XML_PATH_CURRENCY_BASE,
            $this->getScope(),
            $this->getScopeId()
        );
        if (!Config::isSupportedBaseCurrency($baseCurrency)) {
            throw new LocalizedException(
                __(
                    'Coinbase Business can only be enabled for a %1 base currency (current base currency: %2). '
                    . 'Change the base currency, or enable the method only at the website scope that uses %1.',
                    Config::SUPPORTED_BASE_CURRENCY,
                    $baseCurrency !== '' ? $baseCurrency : '(none)'
                )
            );
        }

        $environment = (string) $this->getFieldsetDataValue('environment');
        if ($environment === '') {
            $environment = (string) $this->_config->getValue(
                $this->siblingPath('environment'),
                $this->getScope(),
                $this->getScopeId()
            );
        }

        $secretField = $environment === Environment::SANDBOX
            ? 'sandbox_webhook_secret'
            : 'webhook_secret';

        if (!$this->hasWebhookSecret($secretField)) {
            throw new LocalizedException(
                __('A webhook secret is required to enable Coinbase Business.')
            );
        }

        return parent::beforeSave();
    }

    private function hasWebhookSecret(string $secretField): bool
    {
        $posted = (string) $this->getFieldsetDataValue($secretField);
        // New secret being saved (obscure fields post a mask when unchanged).
        if ($posted !== '' && !preg_match('/^\*+$/', $posted)) {
            return true;
        }

        $stored = (string) $this->_config->getValue(
            $this->siblingPath($secretField),
            $this->getScope(),
            $this->getScopeId()
        );

        return $stored !== '';
    }

    private function siblingPath(string $field): string
    {
        return 'payment/' . Config::CODE . '/' . $field;
    }
}
