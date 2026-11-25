<?php

namespace App\Exceptions\Refund;

use InvalidArgumentException;

class RefundExceedsRemainingBalanceException extends InvalidArgumentException {}
