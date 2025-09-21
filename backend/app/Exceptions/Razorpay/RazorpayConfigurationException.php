<?php

namespace HiEvents\Exceptions\Razorpay;

use HiEvents\Exceptions\BaseException;

class RazorpayConfigurationException extends BaseException
{
    public function __construct(string $message = 'Razorpay configuration error')
    {
        parent::__construct($message);
    }
}