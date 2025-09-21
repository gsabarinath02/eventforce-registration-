<?php

namespace HiEvents\Services\Domain\Payment\Razorpay\EventHandlers;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\RazorpayPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\RazorpayPaymentRepositoryInterface;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayWebhookEventDTO;
use Illuminate\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class PaymentFailedHandler
{
    public function __construct(
        private readonly RazorpayPaymentRepositoryInterface $razorpayPaymentRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly DatabaseManager $databaseManager,
        private readonly LoggerInterface $logger,
        private readonly Repository $cache,
    ) {
    }

    /**
     * Handle payment.failed webhook event
     */
    public function handleEvent(RazorpayWebhookEventDTO $event): void
    {
        $paymentEntity = $event->getPaymentEntity();
        
        if (!$paymentEntity || empty($paymentEntity['id'])) {
            $this->logger->error('Invalid payment.failed event: missing payment entity', [
                'event' => $event->event,
            ]);
            return;
        }

        $paymentId = $paymentEntity['id'];
        
        if ($this->isEventAlreadyHandled($event->event, $paymentId)) {
            $this->logger->info('Payment failed event already handled', [
                'payment_id' => $paymentId,
                'event' => $event->event,
            ]);
            return;
        }

        try {
            $this->databaseManager->transaction(function () use ($event, $paymentEntity, $paymentId) {
                $razorpayPayment = $this->findRazorpayPayment($paymentEntity);
                
                if (!$razorpayPayment) {
                    $this->logger->error('Razorpay payment not found for failed event', [
                        'payment_id' => $paymentId,
                        'order_id' => $paymentEntity['order_id'] ?? null,
                    ]);
                    return;
                }

                $this->updatePaymentRecord($razorpayPayment, $paymentEntity);
                
                $this->updateOrderStatus($razorpayPayment->getOrderId(), OrderPaymentStatus::PAYMENT_FAILED);

                $this->markEventAsHandled($event->event, $paymentId);

                $this->logger->info('Payment failed event processed successfully', [
                    'payment_id' => $paymentId,
                    'order_id' => $razorpayPayment->getOrderId(),
                    'error_code' => $paymentEntity['error_code'] ?? null,
                    'error_description' => $paymentEntity['error_description'] ?? null,
                ]);
            });

        } catch (Throwable $exception) {
            $this->logger->error('Failed to process payment.failed event', [
                'error' => $exception->getMessage(),
                'payment_id' => $paymentId,
                'event' => $event->event,
            ]);
            throw $exception;
        }
    }

    private function findRazorpayPayment(array $paymentEntity): ?RazorpayPaymentDomainObjectAbstract
    {
        $orderId = $paymentEntity['order_id'] ?? null;
        
        if (!$orderId) {
            return null;
        }

        return $this->razorpayPaymentRepository->findFirstWhere([
            RazorpayPaymentDomainObjectAbstract::RAZORPAY_ORDER_ID => $orderId,
        ]);
    }

    private function updatePaymentRecord(RazorpayPaymentDomainObjectAbstract $razorpayPayment, array $paymentEntity): void
    {
        $errorInfo = [
            'error_code' => $paymentEntity['error_code'] ?? null,
            'error_description' => $paymentEntity['error_description'] ?? null,
            'error_source' => $paymentEntity['error_source'] ?? null,
            'error_step' => $paymentEntity['error_step'] ?? null,
            'error_reason' => $paymentEntity['error_reason'] ?? null,
        ];

        $this->razorpayPaymentRepository->updateWhere(
            attributes: [
                RazorpayPaymentDomainObjectAbstract::RAZORPAY_PAYMENT_ID => $paymentEntity['id'],
                RazorpayPaymentDomainObjectAbstract::LAST_ERROR => array_filter($errorInfo), // Remove null values
            ],
            where: [
                RazorpayPaymentDomainObjectAbstract::ID => $razorpayPayment->getId(),
            ]
        );
    }

    private function updateOrderStatus(int $orderId, OrderPaymentStatus $status): void
    {
        $this->orderRepository->updateWhere(
            attributes: [
                OrderDomainObjectAbstract::PAYMENT_STATUS => $status->name,
            ],
            where: [
                'id' => $orderId,
            ]
        );
    }

    private function isEventAlreadyHandled(string $eventType, string $paymentId): bool
    {
        return $this->cache->has("razorpay_event_{$eventType}_{$paymentId}");
    }

    private function markEventAsHandled(string $eventType, string $paymentId): void
    {
        $this->cache->put("razorpay_event_{$eventType}_{$paymentId}", true, now()->addHours(24));
    }
}