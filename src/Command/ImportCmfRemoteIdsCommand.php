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

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\RemoteIdStore;
use OpenDxp\Console\AbstractCommand;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Note\Listing as NoteListing;
use OpenDxp\Model\Element\Service as ElementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Carries the provider IDs of a Pimcore Customer Management Framework installation over into
 * this bundle's own store.
 *
 * Without them a first sync creates a second interest category, a second interest and a second
 * template next to the ones the audience already has, and every subscriber's interest
 * assignment keeps pointing at the originals. Members need nothing: the provider identifies
 * them by a hash of their address, so an export matches them either way.
 */
#[AsCommand(
    name: 'campaigns:migrate:cmf-remote-ids',
    description: 'Import provider IDs recorded by the Pimcore Customer Management Framework into this bundle.',
)]
class ImportCmfRemoteIdsCommand extends AbstractCommand
{
    /**
     * Note type the Customer Management Framework writes its Mailchimp exports to.
     */
    private const string CMF_NOTE_TYPE = 'export.mailchimp';

    /**
     * Placeholder the framework stores instead of an audience ID for objects that exist once
     * per account, such as templates.
     */
    private const string CMF_ACCOUNT_LIST = 'global';

    /**
     * Notes read per query.
     */
    private const int PAGE_SIZE = 200;

    public function __construct(
        private readonly DriverRegistry $registry,
        private readonly RemoteIdStore $remoteIds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('connector', null, InputOption::VALUE_REQUIRED, 'Connector the imported IDs belong to; required when more than one is configured')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be imported without writing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connectorName = $this->resolveConnector($input->getOption('connector'));

        if ($connectorName === null) {
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $this->io->note('Dry-run mode: nothing will be written.');
        }

        $listsByProviderId = $this->listsByProviderId($connectorName);
        $totals = ['imported' => 0, 'members' => 0, 'gone' => 0, 'unloadable' => 0, 'unmapped' => 0];
        $unmapped = [];
        $unloadable = [];

        foreach ($this->cmfNotes() as $note) {
            // Read the note before loading what it hangs on: most of them carry no provider ID or
            // belong to another audience, and loading an element to throw it away is the expensive
            // half of this command.
            $data = $note->getData();
            $remoteId = (string) ($data['mailchimp_id']['data'] ?? '');

            if ($remoteId === '') {
                continue;
            }

            $providerListId = (string) ($data['list_id']['data'] ?? '');
            $scope = $providerListId === self::CMF_ACCOUNT_LIST
                ? RemoteIdStore::SCOPE_ACCOUNT
                : ($listsByProviderId[$providerListId] ?? null);

            if ($scope === null) {
                ++$totals['unmapped'];
                $unmapped[$providerListId] = true;

                continue;
            }

            try {
                $element = ElementService::getElementById((string) $note->getCtype(), (int) $note->getCid());
            } catch (\Throwable $exception) {
                // A migrated installation keeps notes of classes it no longer loads. Those are
                // exactly the objects still holding provider IDs, so report them separately.
                ++$totals['unloadable'];
                $unloadable[$exception->getMessage()] = true;

                continue;
            }

            if (!$element instanceof ElementInterface) {
                ++$totals['gone'];

                continue;
            }

            // The provider matches members by an address hash, so their IDs carry no meaning here.
            if ($element instanceof NewsletterMemberInterface) {
                ++$totals['members'];

                continue;
            }

            $this->io->text(\sprintf('  %s %s → %s:%s = %s', $note->getCtype(), $note->getCid(), $connectorName, $scope, $remoteId));

            if (!$dryRun) {
                $this->remoteIds->setRemoteId($element, $connectorName, $scope, $remoteId);
            }

            ++$totals['imported'];
        }

        if ($unloadable !== []) {
            $this->io->warning(\sprintf(
                "These elements still hold provider IDs but cannot be loaded; install their classes and run again:\n  %s",
                \implode("\n  ", \array_keys($unloadable)),
            ));
        }

        if ($unmapped !== []) {
            $this->io->warning(\sprintf(
                'No configured list matches these provider audiences: %s. Add them under opendxp_campaigns.lists, or run against the connector that owns them.',
                \implode(', ', \array_keys($unmapped)),
            ));
        }

        $this->io->success(\sprintf(
            'Imported %d, skipped %d member(s), %d deleted element(s), %d unloadable, %d unmapped.',
            $totals['imported'],
            $totals['members'],
            $totals['gone'],
            $totals['unloadable'],
            $totals['unmapped'],
        ));

        return Command::SUCCESS;
    }

    /**
     * @return iterable<\OpenDxp\Model\Element\Note>
     */
    private function cmfNotes(): iterable
    {
        // A framework install writes one of these per member, so they are read in pages like every
        // other listing in this bundle rather than held in memory at once.
        $offset = 0;

        do {
            $listing = new NoteListing();
            $listing->setCondition('type = :type', ['type' => self::CMF_NOTE_TYPE]);
            $listing->setOrderKey('date');
            $listing->setOrder('ASC');
            $listing->setLimit(self::PAGE_SIZE);
            $listing->setOffset($offset);

            $notes = $listing->getNotes();

            yield from $notes;

            $offset += self::PAGE_SIZE;
        } while (\count($notes) === self::PAGE_SIZE);
    }

    /**
     * @return array<string, string> provider audience ID => configured list identifier
     */
    private function listsByProviderId(string $connectorName): array
    {
        $lists = [];

        foreach ($this->registry->getListConfigs() as $name => $listConfig) {
            if ($listConfig->connectorName === $connectorName) {
                $lists[$listConfig->providerListId] = $name;
            }
        }

        return $lists;
    }

    private function resolveConnector(?string $requested): ?string
    {
        if ($requested !== null) {
            return $requested;
        }

        $sole = $this->registry->soleConnectorName();

        if ($sole !== null) {
            return $sole;
        }

        $this->io->error(\sprintf(
            'Pass --connector; configured connectors are: %s',
            \implode(', ', $this->registry->getConnectorNames()),
        ));

        return null;
    }
}
