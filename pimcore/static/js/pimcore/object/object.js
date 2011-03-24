/**
 * Pimcore
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.pimcore.org/license
 *
 * @copyright  Copyright (c) 2009-2010 elements.at New Media Solutions GmbH (http://www.elements.at)
 * @license    http://www.pimcore.org/license     New BSD License
 */

pimcore.registerNS("pimcore.object.object");
pimcore.object.object = Class.create(pimcore.object.abstract, {

    initialize: function(id) {

        pimcore.plugin.broker.fireEvent("preOpenObject", this, "object");

        this.addLoadingPanel();

        this.id = parseInt(id);

        this.edit = new pimcore.object.edit(this);

        this.properties = new pimcore.settings.properties(this, "object");
        this.versions = new pimcore.object.versions(this);
        this.scheduler = new pimcore.settings.scheduler(this, "object");
        this.permissions = new pimcore.object.permissions(this);
        this.dependencies = new pimcore.settings.dependencies(this, "object");
        this.reports = new pimcore.report.panel("object_concrete", this);
        this.getData();
    },

    getData: function () {
        Ext.Ajax.request({
            url: "/admin/object/get/",
            params: {id: this.id},
            success: this.getDataComplete.bind(this)
        });
    },

    getDataComplete: function (response) {

        try {
            this.data = Ext.decode(response.responseText);

            if (typeof this.data.editlock == "object") {
                pimcore.helpers.lockManager(this.id, "object", "object", this.data);
                throw "object is locked";
            }

            this.addTab();

            try {
                Ext.getCmp("pimcore_panel_tree_objects").expand();
                var tree = pimcore.globalmanager.get("layout_object_tree");
                tree.tree.selectPath(this.data.idPath);
            } catch (e) {
            }
            
            this.startChangeDetector();            
        }
        catch (e) {
            console.log(e);
            pimcore.helpers.closeObject(this.id);
        }
    },

    addTab: function () {


        this.tabPanel = Ext.getCmp("pimcore_panel_tabs");
        var tabId = "object_" + this.id;
        this.tab = new Ext.Panel({
            id: tabId,
            title: this.data.general.o_key,
            closable:true,
            layout: "border",
            items: [this.getLayoutToolbar(),this.getTabPanel()],
            object: this,
            iconCls: "pimcore_icon_object"
        });

        this.tab.on("activate", function () {
            this.tab.doLayout();
            pimcore.layout.refresh();
        }.bind(this));

        this.tab.on("beforedestroy", function () {
            Ext.Ajax.request({
                url: "/admin/misc/unlock-element",
                params: {
                    id: this.id,
                    type: "object"
                }
            });
        }.bind(this));

        // remove this instance when the panel is closed
        this.tab.on("destroy", function () {
            pimcore.globalmanager.remove("object_" + this.id);
        }.bind(this));

        this.tab.on("afterrender", function (tabId) {
            this.tabPanel.activate(tabId);
            pimcore.plugin.broker.fireEvent("postOpenObject", this, "object");
        }.bind(this, tabId));

        this.removeLoadingPanel();

        this.tabPanel.add(this.tab);

        // recalculate the layout
        pimcore.layout.refresh();
    },

    getTabPanel: function () {

        var items = [];

        items.push(this.edit.getLayout(this.data.layout));

        if (this.isAllowed("properties")) {
            items.push(this.properties.getLayout());
        }
        if (this.isAllowed("versions")) {
            items.push(this.versions.getLayout());
        }
        if (this.isAllowed("settings")) {
            items.push(this.scheduler.getLayout());
        }
        if (this.isAllowed("permissions")) {
            items.push(this.permissions.getLayout());
        }
        items.push(this.dependencies.getLayout());
        
        var reportLayout = this.reports.getLayout();
        if(reportLayout) {
            items.push(reportLayout);
        }
        
        var tabbar = new Ext.TabPanel({
            tabPosition: "top",
            region:'center',
            deferredRender:false,
            enableTabScroll:true,
            border: false,
            items: items,
            activeTab: 0
        });

        return tabbar;
    },

    getLayoutToolbar : function () {

        if (!this.toolbar) {

            var buttons = [];

            this.toolbarButtons = {};


            this.toolbarButtons.save = new Ext.SplitButton({
                text: t('save'),
                iconCls: "pimcore_icon_save_medium",
                scale: "medium",
                handler: this.save.bind(this),
                menu:[{
                        text: t('save_close'),
                        iconCls: "pimcore_icon_save",
                        handler: this.saveClose.bind(this)
                    }]
            });


            this.toolbarButtons.publish = new Ext.SplitButton({
                text: t('save_and_publish'),
                iconCls: "pimcore_icon_publish_medium",
                scale: "medium",
                handler: this.publish.bind(this),
                menu: [{
                        text: t('save_pubish_close'),
                        iconCls: "pimcore_icon_save",
                        handler: this.publishClose.bind(this)
                    },
                    {
                        text: t('save_only_new_version'),
                        iconCls: "pimcore_icon_save",
                        handler: this.save.bind(this)
                    },
                    {
                        text: t('save_only_scheduled_tasks'),
                        iconCls: "pimcore_icon_save",
                        handler: this.save.bind(this, "scheduler","scheduler")
                    }
                ]
            });


            this.toolbarButtons.unpublish = new Ext.Button({
                text: t('unpublish'),
                iconCls: "pimcore_icon_unpublish_medium",
                scale: "medium",
                handler: this.unpublish.bind(this)
            });

            this.toolbarButtons.reload = new Ext.Button({
                text: t('reload'),
                iconCls: "pimcore_icon_reload_medium",
                scale: "medium",
                handler: this.reload.bind(this)
            });

            /*this.toolbarButtons.remove = new Ext.Button({
             text: t("delete"),
             iconCls: "pimcore_icon_delete_medium",
             scale: "medium",
             handler: this.remove.bind(this)
             });*/

            if (this.isAllowed("publish")) {
                buttons.push(this.toolbarButtons.publish);
            }
            if (this.isAllowed("save") && !this.isAllowed("publish")) {
                buttons.push(this.toolbarButtons.save);
            }
            if (this.isAllowed("unpublish")) {
                buttons.push(this.toolbarButtons.unpublish);
            }

            /*if(this.isAllowed("delete")) {
             buttons.push(this.toolbarButtons.remove);
             }*/

            buttons.push(this.toolbarButtons.reload);

            buttons.push("-");
            buttons.push({
                text: this.data.general.o_id,
                xtype: 'tbtext'
            });


            // version notification
            buttons.push("-");
            this.newerVersionNotification = new Ext.Toolbar.TextItem({
                xtype: 'tbtext',
                text: '<img src="/pimcore/static/img/icon/error.png" align="absbottom" />&nbsp;&nbsp;' + t("this_is_a_newer_not_published_version"),
                scale: "medium",
                hidden: true
            });

            buttons.push(this.newerVersionNotification);

            // check for newer version than the published
            if (this.data.versions.length > 1) {
                if (this.data.general.o_modificationDate != this.data.versions[0].date) {
                    this.newerVersionNotification.show();
                }
            }

            this.toolbar = new Ext.Toolbar({
                id: "object_toolbar_" + this.id,
                region: "north",
                border: false,
                cls: "document_toolbar",
                items: buttons
            });

            this.toolbar.on("afterrender", function () {
                window.setTimeout(function () {
                    if (!this.data.general.o_published) {
                        this.toolbarButtons.unpublish.hide();
                    }
                }.bind(this), 500);
            }.bind(this));
        }

        return this.toolbar;
    },

    activate: function () {
        var tabId = "object_" + this.id;
        this.tabPanel.activate(tabId);
    },

    maskFrames: function () {
        if (this.edit) {
            this.edit.maskFrames();
        }
    },

    unmaskFrames: function () {
        if (this.edit) {
            this.edit.unmaskFrames();
        }
    },

    getSaveData : function (only) {
        var data = {};

        data.id = this.id;

        // get only scheduled tasks
        if (only == "scheduler") {
            try {
                data.scheduler = Ext.encode(this.scheduler.getValues());
                return data;
            }
            catch (e) {
                console.log("scheduler not available");
                return;
            }
        }

        // data
        try {
            data.data = Ext.encode(this.edit.getValues());
        }
        catch (e) {
            //console.log(e)
        }

        // properties
        try {
            data.properties = Ext.encode(this.properties.getValues());
        }
        catch (e) {
            //console.log(e);
        }

        try {
            data.general = Ext.encode(this.data.general);
        }
        catch (e) {
            //console.log(e);
        }

        // scheduler
        try {
            data.scheduler = Ext.encode(this.scheduler.getValues());
        }
        catch (e) {
            //console.log(e);
        }


        return data;
    },

    saveClose: function(only){
        this.save();
        var tabPanel = Ext.getCmp("pimcore_panel_tabs");
        tabPanel.remove(this.tab);
    },

    publishClose: function(){
        this.publish();
        var tabPanel = Ext.getCmp("pimcore_panel_tabs");
        tabPanel.remove(this.tab);
    },


    publish: function (only) {
        this.data.general.o_published = true;

        if(this.save("publish", only)) {
            // toogle buttons
            this.toolbarButtons.unpublish.show();

            // remove class in tree panel
            try {
                pimcore.globalmanager.get("layout_object_tree").tree.getNodeById(this.id).getUI().removeClass("pimcore_unpublished");
            } catch (e) { };
        }
    },

    unpublish: function () {
        this.data.general.o_published = false;
        
        if(this.save("unpublish")) {
            // toogle buttons
            this.toolbarButtons.unpublish.hide();
    
            // set class in tree panel
            try {
                pimcore.globalmanager.get("layout_object_tree").tree.getNodeById(this.id).getUI().addClass("pimcore_unpublished");
            } catch (e) {};
        }
    },

    save : function (task, only) {

        var saveData = this.getSaveData(only);

        if (saveData.data != "false") {

            // check for version notification
            if(this.newerVersionNotification) {
                if(task == "publish") {
                    this.newerVersionNotification.hide();
                } else {
                    this.newerVersionNotification.show();
                }

            }

            Ext.Ajax.request({
                url: '/admin/object/save/task/' + task,
                method: "post",
                params: saveData,
                success: function (response) {
                    try{
                        var rdata = Ext.decode(response.responseText);
                        if (rdata && rdata.success) {
                            pimcore.helpers.showNotification(t("success"), t("your_object_has_been_saved"), "success");
                        }
                        else {
                            pimcore.helpers.showNotification(t("error"), t("error_saving_object"), "error",t(rdata.message));
                        }
                    } catch(e){
                        pimcore.helpers.showNotification(t("error"), t("error_saving_object"), "error");    
                    }
                    // reload versions
                    if (this.versions) {
                        if (typeof this.versions.reload == "function") {
                            this.versions.reload();
                        }
                    }
                }.bind(this)
            });
            
            this.resetChanges();
            
            return true;
        }
        return false;
    },


    remove: function () {
        var tabPanel = Ext.getCmp("pimcore_panel_tabs");
        tabPanel.remove(this.tab);

        var objectNode = pimcore.globalmanager.get("layout_object_tree").tree.getNodeById(this.id)
        var f = pimcore.globalmanager.get("layout_object_tree").remove.bind(objectNode);
        f();
    },

    isAllowed: function (key) {
        return this.data.userPermissions[key];
    },

    reload: function () {
        window.setTimeout(function (id) {
            pimcore.helpers.openObject(id, "object");
        }.bind(window, this.id), 500);

        pimcore.helpers.closeObject(this.id);
    }
});