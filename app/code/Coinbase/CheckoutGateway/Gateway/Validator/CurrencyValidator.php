<?php

declare(strict_types=1);

namespace Coinbase\CheckoutGateway\Gateway\Validator;

use Coinbase\CheckoutGateway\Gateway\Config;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;

/**
 * Restricts the payment method to stores whose base currency is USD.
 *
 * The checkout is created for the order's base grand total denominated as
 * USDC (1:1 with USD). Any other base currency would misrepresent the amount,
 * so Magento's canUseForCurrency() must fail closed for non-USD stores.
 */
class CurrencyValidator extends AbstractValidator
{
    /**
     * @param array<string, mixed> $validationSubject
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $currency = $validationSubject['currency'] ?? null;
        $isValid = Config::isSupportedBaseCurrency(is_string($currency) ? $currency : null);

        return $this->createResult(
            $isValid,
            $isValid ? [] : [__('Coinbase Business is only available for stores with a %1 base currency.', Config::SUPPORTED_BASE_CURRENCY)]
        );
    }
}
