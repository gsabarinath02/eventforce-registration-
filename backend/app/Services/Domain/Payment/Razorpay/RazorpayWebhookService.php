<?php

namespace HiEvents\Services\Domain\Payment\Razorpay;

use HiEvents\Exceptions\Razorpay\RazorpayWebhookVerificationFailedException;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayWebhookEventDTO;
use HiEvents\Services\Domain\Payment\Razorpay\EventHandlers\PaymentAuthorizedHandler;
use HiEvents\Services\Domain\Payment\Razorpay\EventHandlers\PaymentCapturedHandler;
use HiEvents\Services\Domain\Payment\Razorpay\EventHandlers\PaymentFailedHandler;
use HiEvents\Services\Domain\Payment\Razorpay\EventHandlers\RefundProcessedHandler;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use Psr\Log\LoggerInterface;
use Throwable;

class RazorpayWebhookService implements RazorpayWebhookServiceInterface
{
    public function __construct(
        private readonly RazorpayClient $razorpayClient,
        private readonly PaymentAuthorizedHandler $paymentAuthorizedHandler,
        private readonly PaymentCapturedHandler $paymentCapturedHandler,
        private readonly PaymentFailedHandler $paymentFailedHandler,
        private readonly RefundProcessedHandler $refundProcessedHandler,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Verify webhook signature and parse event data
     *
     * @throws RazorpayWebhookVerificationFailedException
     */
    public function verifyAndParseWebhook(string $payload, string $signature): RazorpayWebhookEventDTO
    {
        try {
            // Verify the webhook signature
            if (!$this->verifyWebhookSignature($payload, $signature)) {
                $this->logger->warning('Razorpay webhook signature verification failed', [
                    'payload_length' => strlen($payload),
                ]);
                
                throw new RazorpayWebhookVerificationFailedException(
                    'Invalid webhook signature'
                );
            }

            // Parse the webhook payload
            $webhookData = json_decode($payload, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Razorpay webhook payload parsing failed', [
                    'json_error' => json_last_error_msg(),
                    'payload_length' => strlen($payload),
                ]);
                
                throw new RazorpayWebhookVerificationFailedException(
                    'Invalid webhook payload format'
                );
            }

            $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

            $this->logger->info('Razorpay webhook verified and parsed successfully', [
                'event' => $event->event,
                'payment_id' => $event->getPaymentId(),
                'order_id' => $event->getOrderId(),
                'created_at' => $event->createdAt,
            ]);

            return $event;

        } catch (RazorpayWebhookVerificationFailedException $exception) {
            // Re-throw webhook verification exceptions
            throw $exception;
            
        } catch (Throwable $exception) {
            $this->logger->error('Razorpay webhook processing failed', [
                'error' => $exception->getMessage(),
                'payload_length' => strlen($payload),
            ]);
            
            throw new RazorpayWebhookVerificationFailedException(
                'Failed to process webhook: ' . $exception->getMessage(),
                previous: $exception
            );
        }
    }

    /**
     * Verify webhook signature using HMAC SHA256
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        try {
            return $this->razorpayClient->verifyWebhookSignature($payload, $signature);
            
        } catch (Throwable $exception) {
            $this->logger->error('Razorpay webhook signature verification error', [
                'error' => $exception->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Validate webhook event data
     */
    public function validateWebhookEvent(RazorpayWebhookEventDTO $event): bool
    {
        try {
            // Check if event type is supported
            if (!$this->isSupportedEventType($event->event)) {
                $this->logger->info('Unsupported Razorpay webhook event type', [
                    'event' => $event->event,
                ]);
                return false;
            }

            // Validate required fields based on event type
            if ($event->isPaymentEvent()) {
                $paymentEntity = $event->getPaymentEntity();
                if (empty($paymentEntity) || empty($paymentEntity['id'])) {
                    $this->logger->warning('Invalid payment event: missing payment entity', [
                        'event' => $event->event,
                    ]);
                    return false;
                }
            }

            if ($event->isRefundEvent()) {
                $refundEntity = $event->getRefundEntity();
                if (empty($refundEntity) || empty($refundEntity['id'])) {
                    $this->logger->warning('Invalid refund event: missing refund entity', [
                        'event' => $event->event,
                    ]);
                    return false;
                }
            }

            return true;

        } catch (Throwable $exception) {
            $this->logger->error('Razorpay webhook event validation failed', [
                'error' => $exception->getMessage(),
                'event' => $event->event,
            ]);
            
            return false;
        }
    }

    /**
     * Check if the event type is supported for processing
     */
    public function isSupportedEventType(string $eventType): bool
    {
        $supportedEvents = [
            'payment.authorized',
            'payment.captured',
            'payment.failed',
            'refund.processed',
            'refund.failed',
        ];

        return in_array($eventType, $supportedEvents, true);
    }

    /**
     * Extract order information from webhook event
     */
    public function extractOrderInfo(RazorpayWebhookEventDTO $event): ?array
    {
        try {
            $orderId = $event->getOrderId();
            
            if (empty($orderId)) {
                return null;
            }

            // Extract order notes which contain our internal order information
            $orderEntity = $event->getOrderEntity();
            $paymentEntity = $event->getPaymentEntity();
            
            $notes = $orderEntity['notes'] ?? $paymentEntity['notes'] ?? [];
            
            return [
                'razorpay_order_id' => $orderId,
                'internal_order_id' => $notes['order_id'] ?? null,
                'event_id' => $notes['event_id'] ?? null,
                'order_short_id' => $notes['order_short_id'] ?? null,
                'account_id' => $notes['account_id'] ?? null,
            ];

        } catch (Throwable $exception) {
            $this->logger->error('Failed to extract order info from webhook', [
                'error' => $exception->getMessage(),
                'event' => $event->event,
            ]);
            
            return null;
        }
    }

    /**
     * Process webhook event using appropriate event handlers
     */
    public function processWebhookEvent(RazorpayWebhookEventDTO $event): void
    {
        try {
            $this->logger->info('Processing Razorpay webhook event', [
                'event' => $event->event,
                'payment_id' => $event->getPaymentId(),
                'order_id' => $event->getOrderId(),
            ]);

            switch ($event->event) {
                case 'payment.authorized':
                    $this->paymentAuthorizedHandler->handleEvent($event);
                    break;

                case 'payment.captured':
                    $this->paymentCapturedHandler->handleEvent($event);
                    break;

                case 'payment.failed':
                    $this->paymentFailedHandler->handleEvent($event);
                    break;

                case 'refund.processed':
                    $this->refundProcessedHandler->handleEvent($event);
                    break;

                default:
                    $this->logger->info('Unsupported Razorpay webhook event type', [
                        'event' => $event->event,
                    ]);
                    break;
            }

        } catch (Throwable $exception) {
            $this->logger->error('Failed to process Razorpay webhook event', [
                'error' => $exception->getMessage(),
                'event' => $event->event,
                'payment_id' => $event->getPaymentId(),
                'order_id' => $event->getOrderId(),
            ]);
            
            throw $exception;
        }
    }
}