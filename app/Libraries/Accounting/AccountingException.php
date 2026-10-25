<?php

namespace App\Libraries\Accounting;

use RuntimeException;

/**
 * Raised when a posting would break an accounting invariant (unbalanced entry,
 * closed period, unknown or non-postable account, missing party).
 */
class AccountingException extends RuntimeException
{
}
