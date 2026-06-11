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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Template;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\TemplateExportCapableInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\UnsupportedDriverOperationException;

readonly class TemplateExportService
{
    public function __construct(private DriverRegistry $registry) {}

    /**
     * Export a mail template to the provider connected to the given connector.
     *
     * @param string         $connectorName the configured connector to export to
     * @param TemplateExport $template      the template to create or update
     *
     * @return string the provider-side template ID
     *
     * @throws UnsupportedDriverOperationException when the connector's driver does not support template export
     */
    public function exportToConnector(string $connectorName, TemplateExport $template): string
    {
        $driver = $this->registry->getDriverForConnector($connectorName);

        if (!$driver instanceof TemplateExportCapableInterface) {
            throw UnsupportedDriverOperationException::templateExportNotSupported($driver->getName());
        }

        return $driver->exportTemplate($template);
    }
}
