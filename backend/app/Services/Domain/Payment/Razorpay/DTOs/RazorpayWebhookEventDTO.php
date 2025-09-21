<?php

namespace HiEvents\Services\Domain\Payment\Razorpay\DTOs;

readonly class RazorpayWebhookEventDTO
{
    public function __construct(
        public string $event,
        public array $payload,
        public int $createdAt,
        public ?string $accountId = null,
        public ?array $contains = null,
    ) {
    }

    /**
     * Create from webhook request data
     */
    public static function fromWebhookData(array $data): self
    {
        return new self(
            event: $data['event'] ?? '',
            payload: $data['payload'] ?? [],
            createdAt: $data['created_at'] ?? time(),
            accountId: $data['account_id'] ?? null,
            contains: $data['contains'] ?? null,
        );
    }

    /**
     * Get the payment entity from the payload
     */
    public function getPaymentEntity(): ?array
    {
        return $this->payload['payment']['entity'] ?? null;
    }

    /**
     * Get the order entity from the payload
     */
    public function getOrderEntity(): ?array
    {
        return $this->payload['order']['entity'] ?? null;
    }

    /**
     * Get the refund entity from the payload
     */
    public function getRefundEntity(): ?array
    {
        return $this->payload['refund']['entity'] ?? null;
    }

    /**
     * Check if this is a payment event
     */
    public function isPaymentEvent(): bool
    {
        return str_starts_with($this->event, 'payment.');
    }

    /**
     * Check if this is a refund event
     */
    public function isRefundEvent(): bool
    {
        return str_starts_with($this->event, 'refund.');
    }

    /**
     * Check if this is an order event
     */
    public function isOrderEvent(): bool
    {
        return str_starts_with($this->event, 'order.');
    }

    /**
     * Get the Razorpay payment ID from the event
     */
    public function getPaymentId(): ?string
    {
        $payment = $this->getPaymentEntity();
        return $payment['id'] ?? null;
    }

    /**
     * Get the Razorpay order ID from the event
     */
    public function getOrderId(): ?string
    {
        $payment = $this->getPaymentEntity();
        $order = $this->getOrderEntity();
        
        return $payment['order_id'] ?? $order['id'] ?? null;
    }

    /**
     * Get the Razorpay refund ID from the event
     */
    public function getRefundId(): ?string
    {
        $refund = $this->getRefundEntity();
        return $refund['id'] ?? null;
    }
}