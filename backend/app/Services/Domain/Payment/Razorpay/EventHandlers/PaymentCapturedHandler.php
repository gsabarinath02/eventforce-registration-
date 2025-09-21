<?php

namespace HiEvents\Services\Domain\Payment\Razorpay\EventHandlers;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\RazorpayPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\CannotAcceptPaymentException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\RazorpayPaymentRepositoryInterface;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayWebhookEventDTO;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Carbon\Carbon;
use Illuminate\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class PaymentCapturedHandler
{
    public function __construct(
        private readonly RazorpayPaymentRepositoryInterface $razorpayPaymentRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AffiliateRepositoryInterface $affiliateRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ProductQuantityUpdateService $quantityUpdateService,
        private readonly DatabaseManager $databaseManager,
        private readonly LoggerInterface $logger,
        private readonly Repository $cache,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
    ) {
    }

    /**
     * Handle payment.captured webhook event
     */
    public function handleEvent(RazorpayWebhookEventDTO $event): void
    {
        $paymentEntity = $event->getPaymentEntity();
        
        if (!$paymentEntity || empty($paymentEntity['id'])) {
            $this->logger->error('Invalid payment.captured event: missing payment entity', [
                'event' => $event->event,
            ]);
            return;
        }

        $paymentId = $paymentEntity['id'];
        
        if ($this->isEventAlreadyHandled($event->event, $paymentId)) {
            $this->logger->info('Payment captured event already handled', [
                'payment_id' => $paymentId,
                'event' => $event->event,
            ]);
            return;
        }

        try {
            $this->databaseManager->transaction(function () use ($event, $paymentEntity, $paymentId) {
                $razorpayPayment = $this->findRazorpayPayment($paymentEntity);
                
                if (!$razorpayPayment) {
                    $this->logger->error('Razorpay payment not found for captured event', [
                        'payment_id' => $paymentId,
                        'order_id' => $paymentEntity['order_id'] ?? null,
                    ]);
                    return;
                }

                $this->validatePaymentAndOrderStatus($razorpayPayment);

                $this->updatePaymentRecord($razorpayPayment, $paymentEntity);
                
                $updatedOrder = $this->updateOrderStatuses($razorpayPayment);

                $this->updateAttendeeStatuses($updatedOrder);

                $this->quantityUpdateService->updateQuantitiesFromOrder($updatedOrder);

                OrderStatusChangedEvent::dispatch($updatedOrder);

                $this->domainEventDispatcherService->dispatch(
                    new OrderEvent(
                        type: DomainEventType::ORDER_CREATED,
                        orderId: $updatedOrder->getId()
                    ),
                );

                $this->markEventAsHandled($event->event, $paymentId);

                $this->logger->info('Payment captured event processed successfully', [
                    'payment_id' => $paymentId,
                    'order_id' => $razorpayPayment->getOrderId(),
                    'amount' => $paymentEntity['amount'] ?? null,
                ]);
            });

        } catch (Throwable $exception) {
            $this->logger->error('Failed to process payment.captured event', [
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

        return $this->razorpayPaymentRepository
            ->loadRelation(new Relationship(OrderDomainObject::class, name: 'order'))
            ->findFirstWhere([
                RazorpayPaymentDomainObjectAbstract::RAZORPAY_ORDER_ID => $orderId,
            ]);
    }

    private function validatePaymentAndOrderStatus(RazorpayPaymentDomainObjectAbstract $razorpayPayment): void
    {
        $order = $razorpayPayment->getOrder();
        
        if (!$order) {
            throw new CannotAcceptPaymentException(
                "Order not found for Razorpay payment: {$razorpayPayment->getId()}"
            );
        }

        if (!in_array($order->getPaymentStatus(), [
            OrderPaymentStatus::AWAITING_PAYMENT->name,
            OrderPaymentStatus::PAYMENT_FAILED->name,
        ], true)) {
            throw new CannotAcceptPaymentException(
                "Order is not awaiting payment. Order: {$order->getId()}"
            );
        }

        // Check if order has expired
        if ($order->getReservedUntil() && (new Carbon($order->getReservedUntil()))->isPast()) {
            throw new CannotAcceptPaymentException(
                "Payment was successful, but order has expired. Order: {$order->getId()}"
            );
        }
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

    private function updateOrderStatuses(RazorpayPaymentDomainObjectAbstract $razorpayPayment): OrderDomainObject
    {
        $updatedOrder = $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->updateFromArray($razorpayPayment->getOrderId(), [
                OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_RECEIVED->name,
                OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
                OrderDomainObjectAbstract::PAYMENT_PROVIDER => PaymentProviders::RAZORPAY->value,
            ]);

        // Update affiliate sales if this order has an affiliate
        if ($updatedOrder->getAffiliateId()) {
            $this->affiliateRepository->incrementSales(
                affiliateId: $updatedOrder->getAffiliateId(),
                amount: $updatedOrder->getTotalGross()
            );
        }

        return $updatedOrder;
    }

    private function updateAttendeeStatuses(OrderDomainObject $updatedOrder): void
    {
        $this->attendeeRepository->updateWhere(
            attributes: [
                'status' => AttendeeStatus::ACTIVE->name,
            ],
            where: [
                'order_id' => $updatedOrder->getId(),
                'status' => AttendeeStatus::AWAITING_PAYMENT->name,
            ],
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