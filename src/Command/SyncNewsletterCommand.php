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

use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberProviderInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message\SyncMemberToListMessage;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\NewsletterManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'campaigns:newsletter:sync',
    description: 'Synchronize member newsletter subscriptions to configured providers. Run daily as a backup to recover from missed webhook events.',
)]
class SyncNewsletterCommand extends Command
{
    public function __construct(
        private readonly DriverRegistry $registry,
        private readonly NewsletterManagerInterface $newsletterManager,
        private readonly MessageBusInterface $bus,
        private readonly MemberResolverInterface $memberResolver,
        private readonly MemberProviderInterface $memberProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('connector', null, InputOption::VALUE_REQUIRED, 'Limit sync to a specific connector')
            ->addOption('list', null, InputOption::VALUE_REQUIRED, 'Limit sync to a specific configured list')
            ->addOption('member', null, InputOption::VALUE_REQUIRED, 'Sync a single member by ID or email address')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would happen without making API calls')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch Messenger messages instead of syncing inline')
        ;
    }

    /**
     * @throws ExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $connectorFilter = $input->getOption('connector');
        $listFilter = $input->getOption('list');
        $member = $input->getOption('member');
        $dryRun = (bool) $input->getOption('dry-run');
        $async = (bool) $input->getOption('async');

        if ($dryRun) {
            $io->note('Dry-run mode: no API calls will be made.');
        }

        $listNames = $this->resolveTargetLists($listFilter, $connectorFilter);

        if (empty($listNames)) {
            $io->warning('No matching lists found. Check --connector and --list options.');

            return Command::SUCCESS;
        }

        if ($member !== null) {
            return $this->syncSingleMember($io, $listNames, $member, $dryRun, $async);
        }

        return $this->syncAllMembers($io, $listNames, $dryRun, $async);
    }

    /**
     * @param string[] $listNames
     *
     * @throws ExceptionInterface
     */
    private function syncSingleMember(
        SymfonyStyle $io,
        array $listNames,
        string $memberValue,
        bool $dryRun,
        bool $async,
    ): int {
        $member = \is_numeric($memberValue)
            ? $this->memberResolver->resolveById((int) $memberValue)
            : $this->memberResolver->resolveByEmail($memberValue);

        if ($member === null) {
            $io->error(\sprintf('Member not found (value=%s).', $memberValue));

            return Command::FAILURE;
        }

        foreach ($listNames as $listName) {
            $io->text(\sprintf('Syncing member %s → list "%s"', $member->getNewsletterEmail(), $listName));

            if ($dryRun) {
                continue;
            }

            if ($async) {
                $this->bus->dispatch(new SyncMemberToListMessage($listName, $member->getNewsletterEmail()));
            } else {
                $this->newsletterManager->syncMemberToList($member, $listName);
            }
        }

        $io->success('Done.');

        return Command::SUCCESS;
    }

    /**
     * @param string[] $listNames
     *
     * @throws ExceptionInterface
     */
    private function syncAllMembers(
        SymfonyStyle $io,
        array $listNames,
        bool $dryRun,
        bool $async,
    ): int {
        foreach ($listNames as $listName) {
            $io->section(\sprintf('Syncing all members → list "%s"', $listName));
            $count = 0;

            foreach ($this->memberProvider->findByList($listName) as $member) {
                $io->text(\sprintf('  %s', $member->getNewsletterEmail()));

                if (!$dryRun) {
                    if ($async) {
                        $this->bus->dispatch(new SyncMemberToListMessage($listName, $member->getNewsletterEmail()));
                    } else {
                        $this->newsletterManager->syncMemberToList($member, $listName);
                    }
                }

                ++$count;
            }

            $io->text(\sprintf('  → %d member(s) processed.', $count));
        }

        $io->success('Sync complete.');

        return Command::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function resolveTargetLists(?string $listFilter, ?string $connectorFilter): array
    {
        $allLists = $this->registry->getListConfigs();
        $result = [];

        foreach ($allLists as $name => $listConfig) {
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
