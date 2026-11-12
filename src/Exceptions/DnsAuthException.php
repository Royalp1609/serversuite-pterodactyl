<?php

namespace ServerSuite\Exceptions;

/**
 * The DNS provider refused the credentials: the token expired, was revoked
 * or lacks access to the zone (#139). Fixing it needs a new token, not a retry.
 */
class DnsAuthException extends \RuntimeException
{
}
