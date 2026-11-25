<?php

namespace App\Exceptions\Refund;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RefundIdempotencyConflictException extends ConflictHttpException {}
