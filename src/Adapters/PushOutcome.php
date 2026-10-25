<?php

declare(strict_types=1);

namespace SyncGuard\Adapters;

/**
 * Enum PushOutcome
 *
 * Backed enum mapping directly to the `sync_attempts.outcome` column in MySQL.
 * Represents the normalized delivery status across disparate external OTA channels.
 */
enum PushOutcome: string
{
    case Success   = 'success';
    case Timeout   = 'timeout';
    case HttpError = 'http_error';
    case Rejected  = 'rejected';
}
