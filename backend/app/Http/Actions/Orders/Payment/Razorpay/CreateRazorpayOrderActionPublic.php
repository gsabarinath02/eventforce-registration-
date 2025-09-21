<?php

namespace HiEvents\Http\Actions\Orders\Payment\Razorpay;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Order\Payment\Razorpay\CreateRazorpayOrderHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateRazorpayOrderActionPublic extends BaseAction
{
    public function __construct(
        private readonly CreateRazorpayOrderHandler $createRazorpayOrderHandler
    ) {
    }

    public function __invoke(int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $razorpayOrder = $this->createRazorpayOrderHandler->handle($orderShortId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->jsonResponse([
            'razorpay_order_id' => $razorpayOrder->razorpayOrderId,
            'amount' => $razorpayOrder->amount,
            'currency' => $razorpayOrder->currency,
            'receipt' => $razorpayOrder->receipt,
            'status' => $razorpayOrder->status,
            // Provide public key to match frontend type and ease client config
            'key_id' => config('services.razorpay.key_id'),
        ]);
    }
}