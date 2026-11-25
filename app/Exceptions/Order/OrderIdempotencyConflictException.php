<?php

namespace App\Exceptions\Order;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OrderIdempotencyConflictException extends ConflictHttpException {}
