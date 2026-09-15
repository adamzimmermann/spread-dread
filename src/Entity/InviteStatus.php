<?php

namespace App\Entity;

/**
 * Only these three are ever persisted. Expiry is derived from Invite::expiresAt
 * at read time — nothing sweeps the table, so a stored "expired" could only ever
 * be stale.
 */
enum InviteStatus: string
{
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
}
