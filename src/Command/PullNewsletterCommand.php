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

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\RemoteMember;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\DriverException;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\IncomingMemberSync;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\OutboundSyncSuppressor;
use OpenDxp\Console\AbstractCommand;
use OpenDxp\Model\Element\DuplicateFullPathException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'campaigns:newsletter:pull',
    description: 'Pull recently changed members (status + merge fields) from configured providers back into OpenDXP. Run nightly as a backup to recover from missed webhook events.',
)]
class PullNewsletterCommand extends AbstractCommand
{
    public function __construct(
        private readonly DriverRegistry $registry,
        private readonly IncomingMemberSync $incomingSync,
        private readonly OutboundSyncSuppressor $suppressor,
        private readonly ?MemberResolverInterface $memberResolver = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('connector', null, InputOption::VALUE_REQUIRED, 'Limit pull to a specific connector')
            ->addOption('list', null, InputOption::VALUE_REQUIRED, 'Limit pull to a specific configured list')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only pull members changed since this date (relative like "-1 day" or an absolute date)', '-3 days')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would change without writing to OpenDXP')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->memberResolver === null) {
            $this->io->error('Set opendxp_campaigns.member_class to enable pull sync.');

            return Command::FAILURE;
        }

        $connectorFilter = $input->getOption('connector');
        $listFilter = $input->getOption('list');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $since = new CarbonImmutable((string) $input->getOption('since'));
        } catch (\Exception $exception) {
            $this->io->error(
                \sprintf(
                    'Invalid --since value "%s": %s',
                    $input->getOption('since'),
                    $exception->getMessage()
                )
            );

            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->io->note('Dry-run mode: no changes will be written to OpenDXP.');
        }

        $this->io->text(
            \sprintf('Pulling members changed since %s', $since->toAtomString())
        );

        $listNames = $this->resolveTargetLists($listFilter, $connectorFilter);

        if (empty($listNames)) {
            $this->io->warning('No matching lists found. Check --connector and --list options.');

            return Command::SUCCESS;
        }

        $totals = ['processed' => 0, 'updated' => 0, 'unchanged' => 0, 'notFound' => 0];

        foreach ($listNames as $listName) {
            $this->pullList($listName, $since, $dryRun, $totals);
        }

        $this->io->success(\sprintf(
            'Pull complete. Processed %d, updated %d, unchanged %d, not found %d.',
            $totals['processed'],
            $totals['updated'],
            $totals['unchanged'],
            $totals['notFound'],
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array{processed: int, updated: int, unchanged: int, notFound: int} $totals
     */
    private function pullList(
        string $listName,
        CarbonInterface $since,
        bool $dryRun,
        array &$totals,
    ): void {
        $this->io->section(\sprintf('Pulling list "%s"', $listName));

        $listConfig = $this->registry->getListConfig($listName);
        $driver = $this->registry->getDriverForList($listName);

        try {
            foreach ($driver->listChangedMembers($listConfig->providerListId, $since) as $remote) {
                ++$totals['processed'];
                $this->pullMember($listName, $remote, $dryRun, $totals);
            }
        } catch (DriverException $exception) {
            $this->io->error(
                \sprintf('Provider error while pulling list "%s": %s', $listName, $exception->getMessage())
            );
        }
    }

    /**
     * @param array{processed: int, updated: int, unchanged: int, notFound: int} $totals
     */
    private function pullMember(
        string $listName,
        RemoteMember $remote,
        bool $dryRun,
        array &$totals,
    ): void {
        if ($remote->email === '') {
            return;
        }

        $member = $this->memberResolver?->resolveByEmail($remote->email);

        if ($member === null) {
            ++$totals['notFound'];
            $this->io->text(\sprintf('  <comment>not found</comment>  %s', $remote->email));

            return;
        }

        if ($dryRun) {
            $status = $remote->status !== null ? $remote->status->value : 'n/a';
            $this->io->text(\sprintf('  would sync  %s (status: %s)', $remote->email, $status));

            return;
        }

        if ($this->applyRemoteMember($member, $listName, $remote)) {
            try {
                // Suppress the outbound sync listener: this save applies provider state,
                // pushing it straight back would be a redundant round-trip.
                $this->suppressor->suppress(
                    static fn () => $member->save(['versionNote' => '[OpenDXP Campaigns] Updated by pull sync']),
                );
                ++$totals['updated'];
                $this->io->text(\sprintf('  <info>updated</info>    %s', $remote->email));
            } catch (DuplicateFullPathException $exception) {
                $this->io->text(
                    \sprintf('  <error>failed</error>    %s → %s', $remote->email, $exception->getMessage()),
                );
            }

            return;
        }

        ++$totals['unchanged'];
    }

    private function applyRemoteMember(
        NewsletterMemberInterface $member,
        string $listName,
        RemoteMember $remote,
    ): bool {
        $statusChanged = $remote->status !== null
            && $this->incomingSync->applyStatus($member, $listName, $remote->status, 'sync.pull');

        // Evaluated independently (not short-circuited) so merge fields sync even when the status did not change.
        $mergeChanged = $this->incomingSync->applyMergeFields($member, $listName, $remote->mergeFields);

        return $statusChanged || $mergeChanged;
    }

    /**
     * @return string[]
     */
    private function resolveTargetLists(?string $listFilter, ?string $connectorFilter): array
    {
        $result = [];

        foreach ($this->registry->getListConfigs() as $name => $listConfig) {
            if ($listFilter !== null && $name !== $listFilter) {
                continue;
            }

            if ($connectorFilter !== null && $listConfig->connectorName !== $connectorFilter) {
                continue;
            }

            $result[] = $name;
        }

        return $result;
    }
}
