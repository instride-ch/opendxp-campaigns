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
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\ConnectorNotFoundException;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\DriverException;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\UnsupportedDriverOperationException;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\RemoteIdStore;
use OpenDxp\Helper\Mail as MailHelper;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Document\Service as DocumentService;
use OpenDxp\Model\Site;
use OpenDxp\Tool;
use OpenDxp\Tool\Frontend;

readonly class TemplateExportService
{
    /** Newsletters are authored as email documents, the same type the Customer Management Framework exports. */
    private const string EXPORTABLE_DOCUMENT_TYPE = 'email';

    public function __construct(
        private DriverRegistry $registry,
        private RemoteIdStore $remoteIds,
        private ?string $hostUrl = null,
    ) {}

    /**
     * Render an OpenDXP document and create or update it as a template on the provider.
     *
     * The provider template ID is kept in a Note on the document, so renaming the document
     * updates the existing template instead of creating a second one.
     *
     * @return string the provider-side template ID
     *
     * @throws ConnectorNotFoundException          when no connector of that name is configured
     * @throws UnsupportedDriverOperationException when the connector's driver cannot export templates
     * @throws DriverException                     when the provider refuses the template
     */
    public function exportDocument(PageSnippet $document, string $connectorName): string
    {
        $template = new TemplateExport(
            $this->templateName($document),
            $this->renderHtml($document, $connectorName),
            $this->remoteIds->getRemoteId($document, $connectorName, RemoteIdStore::SCOPE_ACCOUNT),
        );

        $remoteId = $this->exportToConnector($connectorName, $template);

        if ($remoteId !== $template->providerTemplateId) {
            $this->remoteIds->setRemoteId($document, $connectorName, RemoteIdStore::SCOPE_ACCOUNT, $remoteId);
        }

        return $remoteId;
    }

    /**
     * Export an already rendered template to the provider connected to the given connector.
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

    public function isExportable(PageSnippet $document): bool
    {
        return $document->getType() === self::EXPORTABLE_DOCUMENT_TYPE;
    }

    /**
     * The stored remote ID identifies the template, so this is a label for the provider's UI
     * and carries no meaning for the export.
     */
    public function templateName(PageSnippet $document): string
    {
        return (string) $document->getKey();
    }

    /**
     * Renders the document the way OpenDXP renders a mail: CSS embedded and every path
     * absolute, because the provider serves the template from its own domain.
     */
    public function renderHtml(PageSnippet $document, string $connectorName): string
    {
        $driver = $this->registry->getDriverForConnector($connectorName);

        if (!$driver instanceof TemplateExportCapableInterface) {
            throw UnsupportedDriverOperationException::templateExportNotSupported($driver->getName());
        }

        $html = DocumentService::render($document, $this->renderContext($document));
        $html = MailHelper::embedAndModifyCss($html, $document);

        $html = $driver->protectPlaceholders($html);
        $html = MailHelper::setAbsolutePaths($html, $document);

        $siteHost = $this->siteHostUrl($document);

        if ($this->hostUrl !== null && $siteHost !== $this->hostUrl) {
            $html = $this->rewriteHost($html, $siteHost, $this->hostUrl);
        }

        return $driver->restorePlaceholders($html);
    }

    /**
     * Puts the render in the document's own site and language.
     *
     * An export runs from the command line or the admin, where neither is known, and website
     * settings are stored per site and per language: without both, every one of them reads empty.
     * A template whose footer links come from settings then ships an empty href, which
     * setAbsolutePaths() turns into a link pointing back at the site (measured 2026-08-20).
     *
     * The site is process-wide state with no way back — acceptable because an export request ends
     * right after, and a request that already runs inside a site is left alone.
     *
     * @return array<string, string> request attributes for the render
     */
    private function renderContext(PageSnippet $document): array
    {
        $site = Frontend::getSiteForDocument($document);

        if ($site !== null && !Site::isSiteRequest()) {
            Site::setCurrentSite($site);
        }

        $language = $document->getProperty('language');

        return \is_string($language) && $language !== '' ? ['_locale' => $language] : [];
    }

    /**
     * The host setAbsolutePaths() just built the links from. Passing the configured host to that
     * function instead would drop the site root path from every internal link, because it only
     * strips that prefix when it resolves the site itself.
     */
    private function siteHostUrl(PageSnippet $document): string
    {
        $site = Frontend::getSiteForDocument($document);

        if ($site === null) {
            return Tool::getHostUrl();
        }

        return Tool::getRequestScheme() . '://' . $site->getMainDomain();
    }

    /**
     * Swaps the host in link targets only, so the same string appearing in the mail copy stays put.
     */
    public function rewriteHost(string $html, string $from, string $to): string
    {
        return (string) \preg_replace(
            '@(href|src|srcset)(\s*=\s*["\'])' . \preg_quote($from, '@') . '@i',
            '$1$2' . $to,
            $html
        );
    }

}
