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

use OpenDxp\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
use OpenDxp\Model\DataObject\ClassDefinition\Service as ClassDefinitionService;
use OpenDxp\Model\DataObject\Exception\DefinitionWriteException;
use OpenDxp\Model\DataObject\Fieldcollection\Definition;
use Override;

class Installer extends SettingsStoreAwareInstaller
{
    private const string FIELDCOLLECTION_KEY = 'CampaignNewsletterSubscription';

    public function __construct(OpenDxpCampaignsBundle $bundle)
    {
        parent::__construct($bundle);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function install(): void
    {
        $this->installFieldcollection();
        parent::install();
    }

    /**
     * @throws DefinitionWriteException
     * @throws \Exception
     */
    #[Override]
    public function uninstall(): void
    {
        $this->uninstallFieldcollection();
        parent::uninstall();
    }

    /**
     * @throws \Exception
     */
    private function installFieldcollection(): void
    {
        $jsonFile = __DIR__ . '/Resources/install/fieldcollections/' . self::FIELDCOLLECTION_KEY . '.json';

        $fieldcollection = Definition::getByKey(self::FIELDCOLLECTION_KEY) ?? new Definition();
        $fieldcollection->setKey(self::FIELDCOLLECTION_KEY);

        $json = (string) \file_get_contents($jsonFile);
        ClassDefinitionService::importFieldCollectionFromJson($fieldcollection, $json, true);
    }

    /**
     * @throws DefinitionWriteException
     * @throws \Exception
     */
    private function uninstallFieldcollection(): void
    {
        $fieldcollection = Definition::getByKey(self::FIELDCOLLECTION_KEY);
        $fieldcollection?->delete();
    }
}
