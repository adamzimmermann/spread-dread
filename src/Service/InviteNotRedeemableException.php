<?php

namespace App\Service;

/**
 * Thrown by InviteService::accept() when the invite passed to it is not
 * redeemable (already accepted, revoked, or expired). A controller can
 * catch this specifically and render a generic "this link is no longer
 * valid" response, without risking a broad catch swallowing unrelated
 * runtime errors.
 */
class InviteNotRedeemableException extends \RuntimeException
{
}
