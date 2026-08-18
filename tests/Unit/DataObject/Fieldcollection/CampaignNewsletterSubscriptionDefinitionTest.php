<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\DataObject\Fieldcollection;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\Fieldcollection\AbstractCampaignNewsletterSubscription;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;

/**
 * OpenDXP generates the concrete fieldcollection class from the JSON definition and makes it
 * extend {@see AbstractCampaignNewsletterSubscription}. An abstract method without a matching
 * field stays unimplemented, which is a fatal error. The generated class lives in the
 * application and cannot be loaded here, so this compares the two sources it is built from.
 */
final class CampaignNewsletterSubscriptionDefinitionTest extends Unit
{
    public function testEveryDefinedFieldHasAnAbstractAccessor(): void
    {
        $declared = $this->abstractMethods();

        foreach ($this->definedFields() as $field) {
            $suffix = \ucfirst($field);

            $this->assertContains('get' . $suffix, $declared, \sprintf('Field "%s" has no abstract getter.', $field));
            $this->assertContains('set' . $suffix, $declared, \sprintf('Field "%s" has no abstract setter.', $field));
        }
    }

    public function testEveryAbstractAccessorMatchesADefinedField(): void
    {
        $fields = \array_map(\ucfirst(...), $this->definedFields());

        foreach ($this->abstractMethods() as $method) {
            $field = \substr($method, 3);

            $this->assertContains(
                $field,
                $fields,
                \sprintf('%s() has no matching field in the fieldcollection definition.', $method),
            );
        }
    }

    /**
     * The status values live in three places: the enum, the status field and the provider status
     * field. Nothing fails when one of them is forgotten, so compare them here.
     */
    public function testStatusSelectOptionsMatchTheEnum(): void
    {
        $expected = \array_map(
            static fn (SubscriptionStatus $case): string => $case->value,
            SubscriptionStatus::cases(),
        );

        foreach (['status', 'providerStatus'] as $field) {
            $this->assertSame(
                $expected,
                $this->selectOptions($field),
                \sprintf('Options of "%s" drifted from SubscriptionStatus.', $field),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function selectOptions(string $fieldName): array
    {
        $file = \dirname(__DIR__, 4) . '/config/install/fieldcollections/CampaignNewsletterSubscription.json';
        $definition = \json_decode((string) \file_get_contents($file), true);
        $options = [];

        $collect = static function (mixed $node) use (&$collect, &$options, $fieldName): void {
            if (!\is_array($node)) {
                return;
            }

            if (($node['name'] ?? null) === $fieldName && isset($node['options'])) {
                $options = \array_map(static fn (array $o): string => (string) $o['value'], $node['options']);
            }

            foreach ($node as $child) {
                $collect($child);
            }
        };

        $collect($definition);

        return $options;
    }

    /**
     * Field entries carry a fieldtype and no children; layout panels carry children.
     *
     * @return list<string>
     */
    private function definedFields(): array
    {
        $file = \dirname(__DIR__, 4) . '/config/install/fieldcollections/CampaignNewsletterSubscription.json';
        $definition = \json_decode((string) \file_get_contents($file), true);
        $fields = [];

        $collect = static function (mixed $node) use (&$collect, &$fields): void {
            if (!\is_array($node)) {
                return;
            }

            if (isset($node['fieldtype'], $node['name']) && !isset($node['children'])) {
                $fields[] = (string) $node['name'];
            }

            foreach ($node as $child) {
                $collect($child);
            }
        };

        $collect($definition);

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function abstractMethods(): array
    {
        $reflection = new \ReflectionClass(AbstractCampaignNewsletterSubscription::class);
        $methods = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_ABSTRACT) as $method) {
            $methods[] = $method->getName();
        }

        return $methods;
    }
}
