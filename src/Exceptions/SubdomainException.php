<?php

namespace ServerSuite\Exceptions;

use RuntimeException;

/** A subdomain action was refused or failed; the message is safe to show the user. */
class SubdomainException extends RuntimeException
{
}
