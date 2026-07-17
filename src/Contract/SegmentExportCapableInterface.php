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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Contract;

/**
 * Optional capability for drivers that support exporting segment groups and
 * segments (Mailchimp: interest categories and interests).
 *
 * Check via instanceof before calling. Every method is an idempotent upsert:
 * pass the previously stored provider ID as $remoteId to update, or null to
 * create. Exporters persist the returned ID via
 * {@see \Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\RemoteIdStore}.
 */
interface SegmentExportCapableInterface
{
    /**
     * Create or update a segment group on the provider.
     *
     * @param string      $listId   the provider-side list/audience ID
     * @param string      $name     the group name (already validated, safe for merge tags)
     * @param string|null $remoteId the stored provider group ID, or null to create
     *
     * @return string the provider-side group ID
     */
    public function exportSegmentGroup(string $listId, string $name, ?string $remoteId): string;

    /**
     * Delete a segment group (and, provider-permitting, its segments) from the list.
     *
     * Must tolerate an already-absent group (treat "not found" as success).
     */
    public function deleteSegmentGroup(string $listId, string $remoteId): void;

    /**
     * Create or update a segment within a group on the provider.
     *
     * @param string      $listId        the provider-side list/audience ID
     * @param string      $groupRemoteId the parent group's provider ID
     * @param string      $name          the segment name (validated, safe for merge tags)
     * @param string|null $remoteId      the stored provider segment ID, or null to create
     *
     * @return string the provider-side segment ID
     */
    public function exportSegment(string $listId, string $groupRemoteId, string $name, ?string $remoteId): string;

    /**
     * Delete a segment from a group on the provider.
     *
     * Must tolerate an already-absent segment (treat "not found" as success).
     */
    public function deleteSegment(string $listId, string $groupRemoteId, string $remoteId): void;
}