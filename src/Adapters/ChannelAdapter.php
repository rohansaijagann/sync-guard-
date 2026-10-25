<?php

declare(strict_types=1);

namespace SyncGuard\Adapters;

/**
 * Interface ChannelAdapter
 *
 * Contract implemented by all booking site adapters (e.g. SiteAAdapter, SiteBAdapter).
 * Isolates the background worker from protocol specifics, endpoint signatures,
 * and external API formatting conventions.
 */
interface ChannelAdapter
{
    /**
     * Dispatch an inventory availability and rate update to an external booking channel.
     *
     * @param array<string, mixed> $update The inventory_updates database row
     * @param array<string, mixed> $channel The channels configuration row
     * @return PushResult Normalized execution result with outcome, latency, and status
     */
    public function push(array $update, array $channel): PushResult;
}
