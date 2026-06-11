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

namespace Instride\Bundle\OpenDxpCampaignsBundle;

use Instride\Bundle\OpenDxpCampaignsBundle\DependencyInjection\OpenDxpCampaignsExtension;
use OpenDxp\Extension\Bundle\AbstractOpenDxpBundle;
use OpenDxp\Extension\Bundle\Installer\InstallerInterface;
use OpenDxp\Extension\Bundle\Traits\PackageVersionTrait;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

class OpenDxpCampaignsBundle extends AbstractOpenDxpBundle
{
    use PackageVersionTrait;

    public function getNiceName(): string
    {
        return 'OpenDXP Campaigns';
    }

    protected function getComposerPackageName(): string
    {
        return 'instride/opendxp-campaigns';
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new OpenDxpCampaignsExtension();
        }

        return $this->extension ?: null;
    }

    public function getInstaller(): InstallerInterface
    {
        return $this->container->get(Installer::class);
    }
}