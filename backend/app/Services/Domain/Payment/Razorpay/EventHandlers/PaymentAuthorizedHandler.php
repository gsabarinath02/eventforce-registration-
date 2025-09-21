<?php

namespace HiEvents\Services\Domain\Payment\Razorpay\EventHandlers;

use HiEvents\DomainObjects\Generated\RazorpayPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\RazorpayPaymentRepositoryInterface;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayWebhookEventDTO;
use Illuminate\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class PaymentAuthorizedHandler
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
     * Handle payment.authorized webhook event
     */
    public function handleEvent(RazorpayWebhookEventDTO $event): void
    {
        $paymentEntity = $event->getPaymentEntity();
        
        if (!$paymentEntity || empty($paymentEntity['id'])) {
            $this->logger->error('Invalid payment.authorized event: missing payment entity', [
                'event' => $event->event,
            ]);
            return;
        }

        $paymentId = $paymentEntity['id'];
        
        if ($this->isEventAlreadyHandled($event->event, $paymentId)) {
            $this->logger->info('Payment authorized event already handled', [
                'payment_id' => $paymentId,
                'event' => $event->event,
            ]);
            return;
        }

        try {
            $this->databaseManager->transaction(function () use ($event, $paymentEntity, $paymentId) {
                $razorpayPayment = $this->findRazorpayPayment($paymentEntity);
                
                if (!$razorpayPayment) {
                    $this->logger->error('Razorpay payment not found for authorized event', [
                        'payment_id' => $paymentId,
                        'order_id' => $paymentEntity['order_id'] ?? null,
                    ]);
                    return;
                }

                $this->updatePaymentRecord($razorpayPayment, $paymentEntity);
                
                // For authorized payments, we typically wait for capture
                // Update order status to indicate payment is authorized but not captured
                $this->updateOrderStatus($razorpayPayment->getOrderId(), OrderPaymentStatus::AWAITING_PAYMENT);

                $this->markEventAsHandled($event->event, $paymentId);

                $this->logger->info('Payment authorized event processed successfully', [
                    'payment_id' => $paymentId,
                    'order_id' => $razorpayPayment->getOrderId(),
                    'amount' => $paymentEntity['amount'] ?? null,
                ]);
            });

        } catch (Throwable $exception) {
            $this->logger->error('Failed to process payment.authorized event', [
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
        $this->razorpayPaymentRepository->updateWhere(
            attributes: [
                RazorpayPaymentDomainObjectAbstract::RAZORPAY_PAYMENT_ID => $paymentEntity['id'],
                RazorpayPaymentDomainObjectAbstract::AMOUNT_RECEIVED => $paymentEntity['amount'] ?? null,
                RazorpayPaymentDomainObjectAbstract::LAST_ERROR => null, // Clear any previous errors
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
                'payment_status' => $status->name,
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