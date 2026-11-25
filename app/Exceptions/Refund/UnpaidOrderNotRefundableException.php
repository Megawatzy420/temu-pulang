<?php

namespace App\Exceptions\Refund;

use DomainException;

class UnpaidOrderNotRefundableException extends DomainException {}
