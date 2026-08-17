<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\OpenDxpCampaignsBundle;

/**
 * BundleConfigLocator resolves config/opendxp relative to getPath(). When the two disagree,
 * no configuration is loaded and no error is raised.
 */
final class OpenDxpCampaignsBundleTest extends Unit
{
    public function testConfigDirectoryLiesWhereTheBundlePathPoints(): void
    {
        $path = (new OpenDxpCampaignsBundle())->getPath();

        $this->assertDirectoryExists($path . '/config/opendxp');
        $this->assertFileExists($path . '/config/opendxp/routing.yaml');
    }

    /**
     * A JS path that points nowhere is not reported either: the admin simply loads no script.
     */
    public function testEveryDeclaredJsPathExistsInThePublicDirectory(): void
    {
        $bundle = new OpenDxpCampaignsBundle();

        foreach ($bundle->getJsPaths() as $jsPath) {
            $this->assertFileExists(
                $bundle->getPath() . \str_replace('/bundles/opendxpcampaigns', '/public', $jsPath)
            );
        }
    }
}
