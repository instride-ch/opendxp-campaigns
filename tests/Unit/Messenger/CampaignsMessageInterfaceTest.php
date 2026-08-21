<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Messenger;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\CampaignsMessageInterface;

/**
 * A message that does not implement CampaignsMessageInterface is not routed and runs
 * synchronously.
 */
final class CampaignsMessageInterfaceTest extends Unit
{
    public function testEveryMessageIsRoutable(): void
    {
        $files = \glob(\dirname(__DIR__, 3) . '/src/Messenger/Message/*.php');

        $this->assertNotEmpty($files, 'No message classes found.');

        foreach ($files as $file) {
            $class = 'Instride\\Bundle\\OpenDxpCampaignsBundle\\Messenger\\Message\\' . \basename($file, '.php');

            $this->assertTrue(
                \is_a($class, CampaignsMessageInterface::class, true),
                \sprintf('%s does not implement CampaignsMessageInterface and would run synchronously.', $class),
            );
        }
    }
}
