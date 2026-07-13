<?php

declare(strict_types=1);

/**
 * OpenDXP Campaigns.
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  2026 instride AG (https://instride.ch)
 * @license    https://github.com/instride-ch/opendxp-campaigns/blob/main/gpl-3.0.txt GNU General Public License version 3 (GPLv3)
 */

namespace Instride\Bundle\OpenDxpCampaignsBundle\Newsletter;

/**
 * Re-entrancy guard that lets inbound provider syncs persist a member without the
 * outbound {@see \Instride\Bundle\OpenDxpCampaignsBundle\EventListener\MemberDataObjectSyncListener}
 * pushing that same change straight back to the provider.
 *
 * Inbound entry points (webhook, pull command) write provider state onto a member
 * and then save() it. That save fires a DataObject postUpdate event which the sync
 * listener would otherwise turn into an outbound push — a redundant round-trip that,
 * for a CLEANED member, would even try to re-subscribe them. Wrapping the inbound
 * save() in {@see self::suppress()} marks the current call stack so the listener
 * skips it. Depth-counted so nested suppressions behave correctly.
 */
final class OutboundSyncSuppressor
{
    private int $depth = 0;

    public function isSuppressed(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Runs $callback with outbound sync suppressed for the duration of the call.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function suppress(callable $callback): mixed
    {
        ++$this->depth;

        try {
            return $callback();
        } finally {
            --$this->depth;
        }
    }
}