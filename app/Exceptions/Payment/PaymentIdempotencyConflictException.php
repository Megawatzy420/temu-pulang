<?php

namespace App\Exceptions\Payment;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PaymentIdempotencyConflictException extends ConflictHttpException {}
