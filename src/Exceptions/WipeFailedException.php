<?php

namespace ServerSuite\Exceptions;

use RuntimeException;

/**
 * A wipe (version or loader switch, world reset, nuclear reset) could not be
 * completed, so the caller must stop rather than carry on as if it had (#136).
 */
class WipeFailedException extends RuntimeException
{
}
