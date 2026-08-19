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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Controller\Admin;

use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\ConnectorNotFoundException;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\DriverException;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\UnsupportedDriverOperationException;
use Instride\Bundle\OpenDxpCampaignsBundle\Template\TemplateExportService;
use OpenDxp\Controller\UserAwareController;
use OpenDxp\Model\Document;
use OpenDxp\Model\Document\PageSnippet;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TemplateExportController extends UserAwareController
{
    public function __construct(
        private readonly DriverRegistry $registry,
        private readonly TemplateExportService $templateExport,
    ) {}

    /**
     * Called from the document toolbar. Without a connector the single configured one is used;
     * with several the names are returned so the toolbar can ask which one.
     */
    #[Route('/template/export', name: 'opendxp_campaigns_admin_template_export', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $document = Document::getById($request->request->getInt('documentId'));

        if (!$document instanceof PageSnippet || !$this->templateExport->isExportable($document)) {
            return $this->json(['success' => false, 'message' => 'No exportable email document found.']);
        }

        if (!$document->isAllowed('publish')) {
            throw $this->createAccessDeniedHttpException();
        }

        $connectorName = $request->request->getString('connector');

        if ($connectorName === '') {
            $connectorName = $this->registry->soleConnectorName();

            if ($connectorName === null) {
                $names = $this->registry->getConnectorNames();

                return $this->json($names === []
                    ? ['success' => false, 'message' => 'No newsletter connector is configured.']
                    : ['success' => false, 'connectors' => $names]);
            }
        }

        try {
            $remoteId = $this->templateExport->exportDocument($document, $connectorName);
        } catch (ConnectorNotFoundException|UnsupportedDriverOperationException|DriverException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()]);
        }

        return $this->json([
            'success' => true,
            'templateId' => $remoteId,
            'templateName' => $this->templateExport->templateName($document),
        ]);
    }
}
