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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Driver;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\ConnectorNotFoundException;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\ListNotFoundException;

readonly class DriverRegistry
{
    /**
     * @param array<string, NewsletterDriverInterface> $connectors  connector name → driver instance
     * @param array<string, ListConfig>                $listConfigs list identifier → list config
     */
    public function __construct(
        private array $connectors,
        private array $listConfigs,
    ) {}

    public function getDriverForConnector(string $connectorName): NewsletterDriverInterface
    {
        if (!isset($this->connectors[$connectorName])) {
            throw ConnectorNotFoundException::forName($connectorName);
        }

        return $this->connectors[$connectorName];
    }

    public function getDriverForList(string $listName): NewsletterDriverInterface
    {
        $listConfig = $this->getListConfig($listName);

        return $this->getDriverForConnector($listConfig->connectorName);
    }

    public function getListConfig(string $listName): ListConfig
    {
        if (!isset($this->listConfigs[$listName])) {
            throw ListNotFoundException::forName($listName);
        }

        return $this->listConfigs[$listName];
    }

    /**
     * @return array<string, ListConfig>
     */
    public function getListConfigs(): array
    {
        return $this->listConfigs;
    }

    /**
     * @return string[]
     */
    public function getConnectorNames(): array
    {
        return \array_keys($this->connectors);
    }

    /**
     * The connector to fall back on when a caller names none. Null once the choice is ambiguous,
     * which includes an install that configured no connector at all.
     */
    public function soleConnectorName(): ?string
    {
        $names = $this->getConnectorNames();

        return \count($names) === 1 ? $names[0] : null;
    }

    /**
     * @return string[]
     */
    public function getListNames(): array
    {
        return \array_keys($this->listConfigs);
    }
}
