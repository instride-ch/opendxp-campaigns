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
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Service as ClassDefinitionService;
use OpenDxp\Model\DataObject\Exception\DefinitionWriteException;
use OpenDxp\Model\DataObject\Fieldcollection\Definition;
use OpenDxp\Model\Exception\NotFoundException;
use OpenDxp\Model\Tool\SettingsStore;
use Override;

class Installer extends SettingsStoreAwareInstaller
{
    private const string FIELDCOLLECTION_KEY = 'CampaignNewsletterSubscription';

    /**
     * Default DataObject class names, used when the corresponding config value is not set.
     */
    private const string DEFAULT_SEGMENT_GROUP_CLASS = 'NewsletterSegmentGroup';
    private const string DEFAULT_SEGMENT_CLASS = 'NewsletterSegment';
    private const string DEFAULT_MEMBER_CLASS = 'NewsletterMember';

    /**
     * Token in NewsletterMember.json replaced with the resolved segment class name so the
     * member's segment relation points at whatever the segment class ends up being called.
     */
    private const string SEGMENT_CLASS_PLACEHOLDER = '%%SEGMENT_CLASS%%';

    /**
     * SettingsStore scope + key recording which class definitions this bundle actually
     * created, so uninstall removes only those and leaves project-supplied classes untouched.
     */
    private const string SETTINGS_STORE_SCOPE = 'opendxp_campaigns';
    private const string INSTALLED_CLASSES_KEY = 'opendxp_campaigns.installed_classes';

    /**
     * @param string|null $memberClass       FQCN configured via opendxp_campaigns.member_class
     * @param string|null $segmentClass      FQCN configured via opendxp_campaigns.segments.segment_class
     * @param string|null $segmentGroupClass FQCN configured via opendxp_campaigns.segments.segment_group_class
     */
    public function __construct(
        OpenDxpCampaignsBundle $bundle,
        private readonly ?string $memberClass = null,
        private readonly ?string $segmentClass = null,
        private readonly ?string $segmentGroupClass = null,
    ) {
        parent::__construct($bundle);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function install(): void
    {
        $this->installFieldcollection();
        $this->installClassDefinitions();

        parent::install();
    }

    /**
     * @throws DefinitionWriteException
     * @throws \Exception
     */
    #[Override]
    public function uninstall(): void
    {
        $this->uninstallClassDefinitions();
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

    /**
     * Creates the ready-made Member / Segment / SegmentGroup class definitions.
     *
     * Each configured class is inspected first: when a DataObject class of that name already
     * exists the import is skipped, so existing projects that supply their own classes (which
     * implement the interfaces / use the traits) are left untouched. Only the classes actually
     * created here are recorded, so uninstall can remove exactly those again.
     *
     * @throws \Exception
     */
    private function installClassDefinitions(): void
    {
        $segmentClassName = $this->resolveClassName($this->segmentClass, self::DEFAULT_SEGMENT_CLASS);
        $installed = [];

        foreach ($this->classDefinitionTargets() as $className => $jsonBasename) {
            // Already present (project-supplied or a previous install) — leave it alone.
            if ($this->findClassByName($className) !== null) {
                continue;
            }

            $class = ClassDefinition::create();
            $class->setName($className);
            $class->setUserOwner(0);

            $json = (string) \file_get_contents(__DIR__ . '/Resources/install/classes/' . $jsonBasename . '.json');
            $json = \str_replace(self::SEGMENT_CLASS_PLACEHOLDER, $segmentClassName, $json);

            ClassDefinitionService::importClassDefinitionFromJson($class, $json, true);

            $installed[] = $className;
        }

        $this->rememberInstalledClasses($installed);
    }

    /**
     * Removes only the class definitions this bundle created (recorded at install time),
     * in reverse installation order so dependents go before their dependencies.
     *
     * @throws \Exception
     */
    private function uninstallClassDefinitions(): void
    {
        foreach (\array_reverse($this->installedClasses()) as $className) {
            $this->findClassByName($className)?->delete();
        }

        SettingsStore::delete(self::INSTALLED_CLASSES_KEY, self::SETTINGS_STORE_SCOPE);
    }

    /**
     * Ordered map of target class name => install JSON basename (under Resources/install/classes).
     *
     * Ordered group → segment → member so dependencies are created before dependents; uninstall
     * walks this in reverse.
     *
     * @return array<string, string>
     */
    private function classDefinitionTargets(): array
    {
        return [
            $this->resolveClassName($this->segmentGroupClass, self::DEFAULT_SEGMENT_GROUP_CLASS) => 'NewsletterSegmentGroup',
            $this->resolveClassName($this->segmentClass, self::DEFAULT_SEGMENT_CLASS) => 'NewsletterSegment',
            $this->resolveClassName($this->memberClass, self::DEFAULT_MEMBER_CLASS) => 'NewsletterMember',
        ];
    }

    /**
     * Derives the DataObject class name (short name) from a configured FQCN,
     * falling back to the given default when nothing is configured.
     */
    private function resolveClassName(?string $fqcn, string $default): string
    {
        if ($fqcn === null || \trim($fqcn) === '') {
            return $default;
        }

        $shortName = \strrchr($fqcn, '\\');

        return $shortName === false ? $fqcn : \substr($shortName, 1);
    }

    /**
     * @param string[] $classNames
     *
     * @throws \Exception
     */
    private function rememberInstalledClasses(array $classNames): void
    {
        if ($classNames === []) {
            SettingsStore::delete(self::INSTALLED_CLASSES_KEY, self::SETTINGS_STORE_SCOPE);

            return;
        }

        SettingsStore::set(
            self::INSTALLED_CLASSES_KEY,
            (string) \json_encode(\array_values($classNames)),
            SettingsStore::TYPE_STRING,
            self::SETTINGS_STORE_SCOPE,
        );
    }

    /**
     * @return string[]
     */
    private function installedClasses(): array
    {
        $setting = SettingsStore::get(self::INSTALLED_CLASSES_KEY, self::SETTINGS_STORE_SCOPE);

        if ($setting === null) {
            return [];
        }

        $decoded = \json_decode((string) $setting->getData(), true);

        if (!\is_array($decoded)) {
            return [];
        }

        return \array_values(\array_filter($decoded, static fn (mixed $value): bool => \is_string($value)));
    }

    private function findClassByName(string $className): ?ClassDefinition
    {
        try {
            $id = (new ClassDefinition())->getDao()->getIdByName($className);
        } catch (NotFoundException) {
            return null;
        }

        try {
            return $id ? ClassDefinition::getById($id) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
