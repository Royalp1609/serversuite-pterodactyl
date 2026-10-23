<?php

namespace ServerSuite\Exceptions;

use RuntimeException;

/** A modpack action was refused or failed; the message is safe to show the user. */
class ModpackException extends RuntimeException
{
}
