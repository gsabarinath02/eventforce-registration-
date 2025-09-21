<?php

namespace HiEvents\Http\Actions\Common\Webhooks;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Order\Payment\Razorpay\RazorpayWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class RazorpayIncomingWebhookAction extends BaseAction
{
    public function __invoke(Request $request): Response
    {
        try {
            $signature = $request->header('X-Razorpay-Signature', '');
            $payload = $request->getContent();

            dispatch(static function (RazorpayWebhookHandler $handler) use ($signature, $payload) {
                $handler->handle($payload, $signature);
            })->catch(function (Throwable $exception) use ($payload) {
                logger()->error(__('Failed to handle incoming Razorpay webhook'), [
                    'exception' => $exception,
                    'payload' => $payload,
                ]);
            });

        } catch (Throwable $exception) {
            logger()?->error($exception->getMessage(), $exception->getTrace());
            return $this->noContentResponse(ResponseCodes::HTTP_BAD_REQUEST);
        }

        return $this->noContentResponse();
    }
}