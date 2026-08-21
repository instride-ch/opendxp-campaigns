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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Command;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\SegmentExportCapableInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\OutboundSyncSuppressor;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\SegmentExporter;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\RemoteIdStore;
use OpenDxp\Console\AbstractCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Brings the group membership a provider holds back into OpenDXP.
 *
 * Membership belongs in OpenDXP and is pushed from there — but an installation coming from the
 * Customer Management Framework has it only at the provider, because the framework pushed segments
 * and never read them back. Left alone, the first full push would send every managed interest as
 * false for every member OpenDXP has no segments for, and the targeting a newsletter relies on
 * would be gone.
 *
 * Nothing is invented here: an interest is matched to an existing segment by name, and its remote
 * ID is adopted, so a later export updates what the audience already has instead of creating a
 * second one next to it. Interests without a match are reported, not created — where a segment
 * belongs in the object tree is the application's decision, not this command's.
 */
#[AsCommand(
    name: 'campaigns:migrate:interests',
    description: 'Adopt the provider\'s interest IDs and the membership behind them into OpenDXP.',
)]
class ImportProviderInterestsCommand extends AbstractCommand
{
    public function __construct(
        private readonly DriverRegistry $registry,
        private readonly RemoteIdStore $remoteIds,
        private readonly SegmentExporter $segmentExporter,
        private readonly MemberResolverInterface $memberResolver,
        private readonly OutboundSyncSuppressor $suppressor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('list', null, InputOption::VALUE_REQUIRED, 'Configured list to read; defaults to every list of every connector')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be adopted and assigned without writing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $this->io->note('Dry-run mode: nothing will be written.');
        }

        $listNames = $input->getOption('list') !== null
            ? [(string) $input->getOption('list')]
            : \array_keys($this->registry->getListConfigs());

        foreach ($listNames as $listName) {
            $this->io->section(\sprintf('List "%s"', $listName));

            $driver = $this->registry->getDriverForList($listName);

            if (!$driver instanceof SegmentExportCapableInterface) {
                $this->io->text('  Driver has no segments; skipping.');

                continue;
            }

            $this->migrateList($listName, $driver, $dryRun);
        }

        $this->io->success('Done.');

        return Command::SUCCESS;
    }

    private function migrateList(string $listName, SegmentExportCapableInterface $driver, bool $dryRun): void
    {
        $listConfig = $this->registry->getListConfig($listName);
        $segmentsByName = $this->segmentsOfListByName($listName);

        /** @var array<string, NewsletterSegmentInterface> $segmentsByRemoteId */
        $segmentsByRemoteId = [];
        $unmatched = [];
        $adopted = 0;

        foreach ($driver->listSegmentGroups($listConfig->providerListId) as $groupRemoteId => $groupName) {
            foreach ($driver->listSegments($listConfig->providerListId, $groupRemoteId) as $remoteId => $interestName) {
                $segment = $segmentsByName[$this->key($groupName, $interestName)] ?? null;

                if ($segment === null) {
                    $unmatched[] = \sprintf('%s / %s', $groupName, $interestName);

                    continue;
                }

                $segmentsByRemoteId[$remoteId] = $segment;
                $stored = $this->remoteIds->getRemoteId($segment, $listConfig->connectorName, $listName);

                if ($stored === $remoteId) {
                    continue;
                }

                $this->io->text(\sprintf('  adopt  %s / %s → %s', $groupName, $interestName, $remoteId));

                if (!$dryRun) {
                    $this->remoteIds->setRemoteId($segment, $listConfig->connectorName, $listName, $remoteId);
                    $this->adoptGroup($segment, $listConfig->connectorName, $listName, $groupRemoteId);
                }

                ++$adopted;
            }
        }

        if ($unmatched !== []) {
            $this->io->warning(\sprintf(
                "No segment of this list carries these names, so their membership cannot be kept."
                . " Create them in OpenDXP and run again:\n  %s",
                \implode("\n  ", $unmatched),
            ));
        }

        $this->io->text(\sprintf('  → %d interest(s) adopted.', $adopted));

        $this->assignMembers($listName, $driver, $listConfig->providerListId, $segmentsByRemoteId, $dryRun);
    }

    /**
     * @param array<string, NewsletterSegmentInterface> $segmentsByRemoteId
     */
    private function assignMembers(
        string $listName,
        SegmentExportCapableInterface $driver,
        string $providerListId,
        array $segmentsByRemoteId,
        bool $dryRun,
    ): void {
        $assigned = 0;
        $unknown = 0;

        foreach ($driver->listMemberInterests($providerListId) as $email => $remoteIds) {
            $held = \array_values(\array_intersect_key($segmentsByRemoteId, \array_flip($remoteIds)));

            if ($held === []) {
                continue;
            }

            $member = $this->memberResolver->resolveByEmail($email);

            if ($member === null) {
                ++$unknown;

                continue;
            }

            $names = \array_map(
                static fn (NewsletterSegmentInterface $segment): string => $segment->getNewsletterSegmentName(),
                $held,
            );
            $this->io->text(\sprintf('  %s → %s', $email, \implode(', ', $names)));

            if (!$dryRun) {
                // The assignment came from the provider, so pushing it straight back is pointless.
                $this->suppressor->suppress(static function () use ($member, $held): void {
                    $member->setNewsletterSegments($held);
                    $member->save();
                });
            }

            ++$assigned;
        }

        $this->io->text(\sprintf('  → %d member(s) assigned, %d not found in OpenDXP.', $assigned, $unknown));
    }

    /**
     * The group is exported as a whole, so its remote ID has to be adopted with the first interest
     * that names it — otherwise the next export creates a second category for the same group.
     */
    private function adoptGroup(
        NewsletterSegmentInterface $segment,
        string $connectorName,
        string $listName,
        string $groupRemoteId,
    ): void {
        $group = $segment->getNewsletterSegmentGroup();

        if ($this->remoteIds->getRemoteId($group, $connectorName, $listName) === $groupRemoteId) {
            return;
        }

        $this->remoteIds->setRemoteId($group, $connectorName, $listName, $groupRemoteId);
    }

    /**
     * @return array<string, NewsletterSegmentInterface> "group / segment" => segment
     */
    private function segmentsOfListByName(string $listName): array
    {
        $byName = [];

        foreach ($this->segmentExporter->segmentsOfList($listName) as [$segment, $group]) {
            $byName[$this->key($group->getNewsletterSegmentGroupName(), $segment->getNewsletterSegmentName())] = $segment;
        }

        return $byName;
    }

    private function key(string $groupName, string $segmentName): string
    {
        return \mb_strtolower(\trim($groupName)) . ' / ' . \mb_strtolower(\trim($segmentName));
    }
}
