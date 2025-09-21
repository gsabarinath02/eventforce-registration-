<?php

namespace HiEvents\Services\Infrastructure\Razorpay;

use HiEvents\Exceptions\Razorpay\CreateRazorpayOrderFailedException;
use HiEvents\Exceptions\Razorpay\RazorpayPaymentVerificationFailedException;
use HiEvents\Exceptions\Razorpay\RazorpayRefundFailedException;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderRequestDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderResponseDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayPaymentDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayRefundDTO;
use HiEvents\Values\MoneyValue;
use Psr\Log\LoggerInterface;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\BadRequestError;
use Razorpay\Api\Errors\ServerError;
use Throwable;

class RazorpayClient
{
    private Api $razorpayApi;

    public function __construct(
        private readonly RazorpayConfigurationService $configurationService,
        private readonly LoggerInterface $logger,
    ) {
        $this->initializeApi();
    }

    /**
     * Initialize Razorpay API client
     */
    private function initializeApi(): void
    {
        $this->configurationService->validateConfiguration();
        
        $this->razorpayApi = new Api(
            $this->configurationService->getKeyId(),
            $this->configurationService->getKeySecret()
        );
    }

    /**
     * Create a Razorpay order
     *
     * @throws CreateRazorpayOrderFailedException
     */
    public function createOrder(CreateRazorpayOrderRequestDTO $orderRequest): CreateRazorpayOrderResponseDTO
    {
        try {
            $orderData = [
                'amount' => $orderRequest->amount->toMinorUnit(),
                'currency' => $orderRequest->currencyCode,
                'receipt' => $orderRequest->receipt ?? 'order_' . $orderRequest->order->getShortId(),
                'notes' => array_merge($orderRequest->notes ?? [], [
                    'order_id' => $orderRequest->order->getId(),
                    'event_id' => $orderRequest->order->getEventId(),
                    'order_short_id' => $orderRequest->order->getShortId(),
                    'account_id' => $orderRequest->account->getId(),
                ]),
            ];

            $this->logger->info('Creating Razorpay order', [
                'order_id' => $orderRequest->order->getId(),
                'amount' => $orderRequest->amount->toMinorUnit(),
                'currency' => $orderRequest->currencyCode,
            ]);

            $razorpayOrder = $this->razorpayApi->order->create($orderData);

            $this->logger->info('Razorpay order created successfully', [
                'razorpay_order_id' => $razorpayOrder['id'],
                'order_id' => $orderRequest->order->getId(),
                'amount' => $razorpayOrder['amount'],
                'status' => $razorpayOrder['status'],
            ]);

            return new CreateRazorpayOrderResponseDTO(
                razorpayOrderId: $razorpayOrder['id'],
                currency: $razorpayOrder['currency'],
                amount: $razorpayOrder['amount'],
                receipt: $razorpayOrder['receipt'],
                status: $razorpayOrder['status'],
                notes: $razorpayOrder['notes'] ?? [],
            );

        } catch (BadRequestError $exception) {
            $this->logger->error('Razorpay order creation failed - Bad Request', [
                'error' => $exception->getMessage(),
                'order_id' => $orderRequest->order->getId(),
                'amount' => $orderRequest->amount->toMinorUnit(),
            ]);

            throw new CreateRazorpayOrderFailedException(
                'Invalid order parameters: ' . $exception->getMessage()
            );

        } catch (ServerError $exception) {
            $this->logger->error('Razorpay order creation failed - Server Error', [
                'error' => $exception->getMessage(),
                'order_id' => $orderRequest->order->getId(),
            ]);

            throw new CreateRazorpayOrderFailedException(
                'Razorpay server error. Please try again later.'
            );

        } catch (Throwable $exception) {
            $this->logger->error('Razorpay order creation failed - Unexpected Error', [
                'error' => $exception->getMessage(),
                'order_id' => $orderRequest->order->getId(),
            ]);

            throw new CreateRazorpayOrderFailedException(
                'Failed to create Razorpay order. Please try again later.'
            );
        }
    }

    /**
     * Verify payment signature using HMAC SHA256
     */
    public function verifyPaymentSignature(string $paymentId, string $orderId, string $signature): bool
    {
        try {
            $expectedSignature = hash_hmac(
                'sha256',
                $orderId . '|' . $paymentId,
                $this->configurationService->getKeySecret()
            );

            $isValid = hash_equals($expectedSignature, $signature);

            $this->logger->info('Payment signature verification', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'is_valid' => $isValid,
            ]);

            return $isValid;

        } catch (Throwable $exception) {
            $this->logger->error('Payment signature verification failed', [
                'error' => $exception->getMessage(),
                'payment_id' => $paymentId,
                'order_id' => $orderId,
            ]);

            return false;
        }
    }

    /**
     * Capture an authorized payment
     *
     * @throws RazorpayPaymentVerificationFailedException
     */
    public function capturePayment(string $paymentId, MoneyValue $amount): RazorpayPaymentDTO
    {
        try {
            $this->logger->info('Capturing Razorpay payment', [
                'payment_id' => $paymentId,
                'amount' => $amount->toMinorUnit(),
            ]);

            $payment = $this->razorpayApi->payment->fetch($paymentId);
            $capturedPayment = $payment->capture(['amount' => $amount->toMinorUnit()]);

            $this->logger->info('Razorpay payment captured successfully', [
                'payment_id' => $paymentId,
                'amount' => $capturedPayment['amount'],
                'status' => $capturedPayment['status'],
            ]);

            return new RazorpayPaymentDTO(
                paymentId: $capturedPayment['id'],
                orderId: $capturedPayment['order_id'],
                amount: $capturedPayment['amount'],
                currency: $capturedPayment['currency'],
                status: $capturedPayment['status'],
                method: $capturedPayment['method'] ?? null,
                captured: $capturedPayment['captured'] ?? false,
                createdAt: $capturedPayment['created_at'] ?? null,
            );

        } catch (BadRequestError $exception) {
            $this->logger->error('Razorpay payment capture failed - Bad Request', [
                'error' => $exception->getMessage(),
                'payment_id' => $paymentId,
            ]);

            throw new RazorpayPaymentVerificationFailedException(
                'Payment capture failed: ' . $exception->getMessage()
            );

        } catch (Throwable $exception) {
            $this->logger->error('Razorpay payment capture failed', [
                'error' => $exception->getMessage(),
                'payment_id' => $paymentId,
            ]);

            throw new RazorpayPaymentVerificationFailedException(
                'Failed to capture payment. Please try again later.'
            );
        }
    }

    /**
     * Get payment details from Razorpay
     *
     * @throws RazorpayPaymentVerificationFailedException
     */
    public function getPaymentDetails(string $paymentId): RazorpayPaymentDTO
    {
        try {
            $this->logger->info('Fetching Razorpay payment details', [
                'payment_id' => $paymentId,
            ]);

            $payment = $this->razorpayApi->payment->fetch($paymentId);

            return new RazorpayPaymentDTO(
                paymentId: $payment['id'],
                orderId: $payment['order_id'],
                amount: $payment['amount'],
                currency: $payment['currency'],
                status: $payment['status'],
                method: $payment['method'] ?? null,
                captured: $payment['captured'] ?? false,
                createdAt: $payment['created_at'] ?? null,
            );

        } catch (BadRequestError $exception) {
            $this->logger->error('Razorpay payment fetch failed - Bad Request', [
                'error' => $exception->getMessage(),
                'payment_id' => $paymentId,
            ]);

            throw new RazorpayPaymentVerificationFailedException(
                'Payment not found: ' . $exception->getMessage()
            );

        } catch (Throwable $exception) {
            $this->logger->error('Razorpay payment fetch failed', [
                'error' => $exception->getMessage(),
                'payment_id' => $paymentId,
            ]);

            throw new RazorpayPaymentVerificationFailedException(
                'Failed to fetch payment details. Please try again later.'
            );
        }
    }

    /**
     * Process a refund for a payment
     *
     * @throws RazorpayRefundFailedException
     */
    public function refundPayment(string $paymentId, MoneyValue $amount): RazorpayRefundDTO
    {
        try {
            $this->logger->info('Processing Razorpay refund', [
                'payment_id' => $paymentId,
                'amount' => $amount->toMinorUnit(),
            ]);

            $refundData = [
                'amount' => $amount->toMinorUnit(),
            ];

            $refund = $this->razorpayApi->payment->fetch($paymentId)->refund($refundData);

            $this->logger->info('Razorpay refund processed successfully', [
                'refund_id' => $refund['id'],
                'payment_id' => $paymentId,
                'amount' => $refund['amount'],
                'status' => $refund['status'],
            ]);

            return new RazorpayRefundDTO(
                refundId: $refund['id'],
                paymentId: $refund['payment_id'],
                amount: $refund['amount'],
                currency: $refund['currency'],
                status: $refund['status'],
                createdAt: $refund['created_at'] ?? null,
            );

        } catch (BadRequestError $exception) {
            $this->logger->error('Razorpay refund failed - Bad Request', [
                'error' => $exception->getMessage(),
                'payment_id' => $paymentId,
            ]);

            throw new RazorpayRefundFailedException(
                'Refund failed: ' . $exception->getMessage()
            );

        } catch (Throwable $exception) {
            $this->logger->error('Razorpay refund failed', [
                'error' => $exception->getMessage(),
                'payment_id' => $paymentId,
            ]);

            throw new RazorpayRefundFailedException(
                'Failed to process refund. Please try again later.'
            );
        }
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        try {
            $expectedSignature = hash_hmac(
                'sha256',
                $payload,
                $this->configurationService->getWebhookSecret()
            );

            $isValid = hash_equals($expectedSignature, $signature);

            $this->logger->info('Webhook signature verification', [
                'is_valid' => $isValid,
            ]);

            return $isValid;

        } catch (Throwable $exception) {
            $this->logger->error('Webhook signature verification failed', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}