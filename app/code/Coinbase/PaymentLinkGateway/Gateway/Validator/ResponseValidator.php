<?php

declare(strict_types=1);

namespace Coinbase\PaymentLinkGateway\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;

class ResponseValidator extends AbstractValidator
{
    public function __construct(
        ResultInterfaceFactory $resultFactory
    ) {
        parent::__construct($resultFactory);
    }

    /**
     * Validate the payment link API response.
     *
     * @param array<string, mixed> $validationSubject
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $response = $validationSubject['response'];
        $errorMessages = [];
        $errorCodes = [];
        $isValid = true;

        // Check for HTTP-level errors
        $httpStatus = $response['http_status_code'] ?? 0;
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $isValid = false;
            $errorType = $response['errorType'] ?? 'unknown_error';
            $errorMessage = $response['errorMessage'] ?? 'Payment link creation failed';
            $errorMessages[] = __($errorMessage);
            $errorCodes[] = $errorType;

            return $this->createResult($isValid, $errorMessages, $errorCodes);
        }

        // Validate required fields
        if (empty($response['id'])) {
            $isValid = false;
            $errorMessages[] = __('Payment link response missing ID.');
        }

        if (empty($response['url'])) {
            $isValid = false;
            $errorMessages[] = __('Payment link response missing URL.');
        }

        // Validate status is ACTIVE
        if (!isset($response['status']) || $response['status'] !== 'ACTIVE') {
            $isValid = false;
            $errorMessages[] = __(
                'Payment link status is "%1", expected "ACTIVE".',
                $response['status'] ?? 'unknown'
            );
        }

        return $this->createResult($isValid, $errorMessages, $errorCodes);
    }
}
