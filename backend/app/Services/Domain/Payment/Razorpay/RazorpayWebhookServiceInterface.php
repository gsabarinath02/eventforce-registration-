<?php

namespace HiEvents\Services\Domain\Payment\Razorpay;

use HiEvents\Exceptions\Razorpay\RazorpayWebhookVerificationFailedException;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayWebhookEventDTO;

interface RazorpayWebhookServiceInterface
{
    /**
     * Verify webhook signature and parse event data
     *
     * @throws RazorpayWebhookVerificationFailedException
     */
    public function verifyAndParseWebhook(string $payload, string $signature): RazorpayWebhookEventDTO;

    /**
     * Verify webhook signature using HMAC SHA256
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /**
     * Validate webhook event data
     */
    public function validateWebhookEvent(RazorpayWebhookEventDTO $event): bool;

    /**
     * Check if the event type is supported for processing
     */
    public function isSupportedEventType(string $eventType): bool;

    /**
     * Extract order information from webhook event
     */
    public function extractOrderInfo(RazorpayWebhookEventDTO $event): ?array;

    /**
     * Process webhook event using appropriate event handlers
     */
    public function processWebhookEvent(RazorpayWebhookEventDTO $event): void;
}