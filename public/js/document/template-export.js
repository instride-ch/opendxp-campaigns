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

opendxp.registerNS('opendxp.bundle.campaigns.document.templateExport');

opendxp.bundle.campaigns.document.templateExport = Class.create({
    initialize: function (documentTab) {
        this.documentTab = documentTab;
    },

    getButtonConfig: function () {
        return {
            text: t('opendxp_campaigns_export_template'),
            iconCls: 'opendxp_material_icon_upload opendxp_material_icon',
            scale: 'medium',
            handler: this.exportTemplate.bind(this, null)
        };
    },

    /**
     * The export renders the persisted document, so unsaved edits would silently not reach the
     * provider. Saving here is not an option either: only a publish writes the document tables,
     * and an export button must not publish on the editor's behalf.
     */
    exportTemplate: function (connector, button) {
        if (this.documentTab.isDirty()) {
            opendxp.helpers.showNotification(
                t('opendxp_campaigns_export_template'),
                t('opendxp_campaigns_export_template_unsaved'),
                'error'
            );

            return;
        }

        this.documentTab.tab.mask();

        Ext.Ajax.request({
            url: Routing.generate('opendxp_campaigns_admin_template_export'),
            method: 'POST',
            params: {
                documentId: this.documentTab.id,
                connector: connector
            },
            success: function (response) {
                this.documentTab.tab.unmask();

                const result = Ext.decode(response.responseText);

                if (result.success) {
                    opendxp.helpers.showNotification(
                        t('opendxp_campaigns_export_template'),
                        sprintf(t('opendxp_campaigns_export_template_success'), result.templateName, result.templateId),
                        'success'
                    );

                    return;
                }

                if (result.connectors && result.connectors.length) {
                    this.showConnectorMenu(result.connectors, button);

                    return;
                }

                opendxp.helpers.showNotification(t('error'), result.message, 'error');
            }.bind(this),
            failure: function () {
                this.documentTab.tab.unmask();
                opendxp.helpers.showNotification(t('error'), t('opendxp_campaigns_export_template_failed'), 'error');
            }.bind(this)
        });
    },

    showConnectorMenu: function (connectors, button) {
        const menu = new Ext.menu.Menu({
            items: connectors.map(function (connector) {
                return {
                    text: connector,
                    handler: this.exportTemplate.bind(this, connector)
                };
            }.bind(this))
        });

        menu.on('hide', function () {
            menu.destroy();
        });

        menu.showBy(button);
    }
});

document.addEventListener(opendxp.events.postOpenDocument, function (event) {
    if (event.detail.type !== 'email') {
        return;
    }

    const documentTab = event.detail.document;
    const exporter = new opendxp.bundle.campaigns.document.templateExport(documentTab);
    const config = exporter.getButtonConfig();

    if (opendxp.helpers.checkIfNewHeadbarLayoutIsEnabled()) {
        documentTab.toolbarSubmenu.menu.add(config);

        return;
    }

    documentTab.toolbar.add(new Ext.Button(config));
});
