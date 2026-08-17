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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Contract;

use Instride\Bundle\OpenDxpCampaignsBundle\Template\TemplateExport;

/**
 * Optional capability for drivers that support HTML template export.
 *
 * Drivers implementing this interface can create or update mail templates
 * on the provider side. Check via instanceof before calling exportTemplate().
 */
interface TemplateExportCapableInterface
{
    /**
     * Create or update a mail template on the provider.
     *
     * A template is identified by its provider ID, which the caller carries. Without one, or
     * when the provider no longer has it, the driver creates a new template.
     *
     * @return string the provider-side template ID
     */
    public function exportTemplate(TemplateExport $template): string;

    /**
     * Hides the provider's own placeholders from a rewriting pass.
     *
     * Making a document's paths absolute treats anything that is not a URL as a relative one, and
     * a placeholder used as a link target looks exactly like that. Only the driver knows what its
     * placeholders look like, so only it can put them out of reach and back again.
     */
    public function protectPlaceholders(string $html): string;

    public function restorePlaceholders(string $html): string;
}