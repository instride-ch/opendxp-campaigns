<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Template;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Template\TemplateExportService;

/**
 * OpenDxp\Helper\Mail::setAbsolutePaths() rewrites anything in href or src that does not start
 * with an excluded scheme, which includes provider merge tags. Masking them as data: URIs is
 * what keeps unsubscribe and archive links working in an exported template.
 */
final class TemplateExportServiceTest extends Unit
{
    private TemplateExportService $service;

    protected function setUp(): void
    {
        $this->service = (new \ReflectionClass(TemplateExportService::class))->newInstanceWithoutConstructor();
    }

    public function testHostIsSwappedInLinkTargets(): void
    {
        $html = '<img src="https://stage.example.com/var/assets/a.png"><a href=\'https://stage.example.com/de/x\'>x</a>';

        $rewritten = $this->service->rewriteHost($html, 'https://stage.example.com', 'https://www.example.com');

        $this->assertStringContainsString('src="https://www.example.com/var/assets/a.png"', $rewritten);
        $this->assertStringContainsString("href='https://www.example.com/de/x'", $rewritten);
    }

    public function testHostIsLeftAloneOutsideLinkTargets(): void
    {
        $html = '<p>Write to us at https://stage.example.com any time.</p>';

        $this->assertSame(
            $html,
            $this->service->rewriteHost($html, 'https://stage.example.com', 'https://www.example.com')
        );
    }

    /**
     * The site root path survives the swap: setAbsolutePaths() already stripped what had to go.
     */
    public function testPathsAreUntouchedByTheHostSwap(): void
    {
        $rewritten = $this->service->rewriteHost(
            '<a href="https://stage.example.com/site-root/de/x">x</a>',
            'https://stage.example.com',
            'https://www.example.com'
        );

        $this->assertStringContainsString('href="https://www.example.com/site-root/de/x"', $rewritten);
    }


}
