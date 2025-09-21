<?php

namespace HiEvents\Services\Domain\Payment\Razorpay;

use HiEvents\Exceptions\Razorpay\CreateRazorpayOrderFailedException;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderRequestDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderResponseDTO;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use Psr\Log\LoggerInterface;

class RazorpayOrderCreationService
{
    private const SUPPORTED_CURRENCIES = ['INR', 'USD', 'EUR', 'GBP', 'SGD', 'AED', 'MYR'];
    private const MIN_AMOUNT_INR = 100; // ₹1.00 in paise
    private const MAX_AMOUNT_INR = 1500000000; // ₹15,00,00,000 in paise

    public function __construct(
        private readonly RazorpayClient $razorpayClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create a Razorpay order for payment processing
     *
     * @throws CreateRazorpayOrderFailedException
     */
    public function createOrder(CreateRazorpayOrderRequestDTO $orderRequest): CreateRazorpayOrderResponseDTO
    {
        $this->logger->info('Razorpay order creation requested', [
            'order_id' => $orderRequest->order->getId(),
            'amount' => $orderRequest->amount->toMinorUnit(),
            'currency' => $orderRequest->currencyCode,
        ]);

        // Validate currency
        $this->validateCurrency($orderRequest->currencyCode);

        // Validate amount
        $this->validateAmount($orderRequest->amount->toMinorUnit(), $orderRequest->currencyCode);

        try {
            $response = $this->razorpayClient->createOrder($orderRequest);

            $this->logger->info('Razorpay order created successfully', [
                'order_id' => $orderRequest->order->getId(),
                'razorpay_order_id' => $response->razorpayOrderId,
                'amount' => $response->amount,
                'status' => $response->status,
            ]);

            return $response;

        } catch (CreateRazorpayOrderFailedException $exception) {
            $this->logger->error('Razorpay order creation failed', [
                'order_id' => $orderRequest->order->getId(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Validate currency is supported by Razorpay
     *
     * @throws CreateRazorpayOrderFailedException
     */
    private function validateCurrency(string $currency): void
    {
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new CreateRazorpayOrderFailedException(
                "Currency '{$currency}' is not supported by Razorpay. Supported currencies: " . 
                implode(', ', self::SUPPORTED_CURRENCIES)
            );
        }
    }

    /**
     * Validate amount is within Razorpay limits
     *
     * @throws CreateRazorpayOrderFailedException
     */
    private function validateAmount(int $amountInMinorUnit, string $currency): void
    {
        // For INR, validate against Razorpay limits
        if ($currency === 'INR') {
            if ($amountInMinorUnit < self::MIN_AMOUNT_INR) {
                throw new CreateRazorpayOrderFailedException(
                    "Amount is too small. Minimum amount for INR is ₹1.00"
                );
            }

            if ($amountInMinorUnit > self::MAX_AMOUNT_INR) {
                throw new CreateRazorpayOrderFailedException(
                    "Amount is too large. Maximum amount for INR is ₹15,00,00,000"
                );
            }
        }

        // General validation - amount must be positive
        if ($amountInMinorUnit <= 0) {
            throw new CreateRazorpayOrderFailedException(
                "Amount must be greater than zero"
            );
        }
    }
}