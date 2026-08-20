<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Model\Config\Backend;

use Coinbase\CheckoutGateway\Gateway\Config;
use Coinbase\CheckoutGateway\Model\Adminhtml\Source\Environment;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class Active extends Value
{
    /**
     * Require a webhook secret only when enabling the payment method so
     * admins can still disable it (or save other settings) with a blank secret.
     */
    public function beforeSave()
    {
        if (!(int) $this->getValue()) {
            return parent::beforeSave();
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
