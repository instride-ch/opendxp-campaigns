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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Newsletter;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\RemoteIdStore;
use OpenDxp\Model\Element\ElementInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Reading goes through the injected cache, so the stored map can be handed in without a database.
 *
 * The key composition is what these cover. It carried a defect for a while — a renamed parameter
 * that mapKey() still referred to under its old name — which killed every segment export and stayed
 * invisible because nothing here exercised it.
 */
class RemoteIdStoreTest extends Unit
{
    public function testRemoteIdIsFoundUnderConnectorAndScope(): void
    {
        $store = $this->storeHolding(['mailchimp:newsletter' => 'abc123']);

        $this->assertSame('abc123', $store->getRemoteId($this->object(), 'mailchimp', 'newsletter'));
    }

    public function testAnotherScopeOfTheSameConnectorIsNotReturned(): void
    {
        $store = $this->storeHolding(['mailchimp:newsletter' => 'abc123']);

        $this->assertNull($store->getRemoteId($this->object(), 'mailchimp', 'other'));
    }

    public function testTheSameScopeOfAnotherConnectorIsNotReturned(): void
    {
        $store = $this->storeHolding(['mailchimp:newsletter' => 'abc123']);

        $this->assertNull($store->getRemoteId($this->object(), 'other', 'newsletter'));
    }

    public function testAllRemoteIdsAreKeyedByScopeWithTheConnectorStripped(): void
    {
        $store = $this->storeHolding([
            'mailchimp:newsletter' => 'abc123',
            'mailchimp:second' => 'def456',
            'other:newsletter' => 'ghi789',
        ]);

        $this->assertSame(
            ['newsletter' => 'abc123', 'second' => 'def456'],
            $store->allRemoteIds($this->object(), 'mailchimp'),
        );
    }

    public function testTheMapIsReadOncePerObject(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->willReturn(['mailchimp:newsletter' => 'abc123']);

        $store = new RemoteIdStore($cache);
        $object = $this->object();

        $store->getRemoteId($object, 'mailchimp', 'newsletter');
        $store->getRemoteId($object, 'mailchimp', 'newsletter');
        $store->allRemoteIds($object, 'mailchimp');
    }

    /**
     * @param array<string, string> $map
     */
    private function storeHolding(array $map): RemoteIdStore
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn($map);

        return new RemoteIdStore($cache);
    }

    private function object(): ElementInterface
    {
        $object = $this->createMock(ElementInterface::class);
        $object->method('getId')->willReturn(42);

        return $object;
    }
}
