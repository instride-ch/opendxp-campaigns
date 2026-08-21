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
use OpenDxp\Extension\Bundle\OpenDxpBundleAdminClassicInterface;
use OpenDxp\Extension\Bundle\Traits\BundleAdminClassicTrait;
use OpenDxp\Extension\Bundle\Traits\PackageVersionTrait;
use Override;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

class OpenDxpCampaignsBundle extends AbstractOpenDxpBundle implements OpenDxpBundleAdminClassicInterface
{
    use BundleAdminClassicTrait;
    use PackageVersionTrait;

    public function getNiceName(): string
    {
        return 'OpenDXP Campaigns';
    }

    public function getJsPaths(): array
    {
        return [
            '/bundles/opendxpcampaigns/js/document/template-export.js',
        ];
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new OpenDxpCampaignsExtension();
        }

        return $this->extension ?: null;
    }

    /** BundleConfigLocator resolves config/opendxp relative to this path. */
    #[Override]
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getInstaller(): InstallerInterface
    {
        $installer = $this->container?->get(Installer::class);

        if (!$installer instanceof InstallerInterface) {
            throw new \LogicException('The campaigns installer is missing from the container.');
        }

        return $installer;
    }

    protected function getComposerPackageName(): string
    {
        return 'instride/opendxp-campaigns';
    }
}
