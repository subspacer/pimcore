<?php
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

class Admin_ObjectController extends Pimcore_Controller_Action_Admin
{

    /**
     * @var Object_Service
     */
    protected $_objectService;

    public function init()
    {
        parent::init();

        // check permissions
        $notRestrictedActions = array();
        if (!in_array($this->_getParam("action"), $notRestrictedActions)) {
            if (!$this->getUser()->isAllowed("objects")) {

                $this->_redirect("/admin/login");
                die();
            }
        }

        $this->_objectService = new Object_Service($this->getUser());
    }


    public function getUserPermissionsAction()
    {

        $object = Object_Abstract::getById($this->_getParam("object"));

        $list = new User_List();
        $list->load();
        $users = $list->getUsers();
        if (!empty($users)) {
            foreach ($users as $user) {
                $permission = $object->getUserPermissions($user);
                $permission->setUser($user);
                $permission->setUserId($user->getId());
                $permission->setUsername($user->getUsername());
                $permissions[] = $permission;
            }
        }

        $object->getUserPermissions($this->getUser());
        if ($object->isAllowed("view")) {
            $this->_helper->json(array("permissions" => $permissions));
        }

        $this->_helper->json(array("success" => false, "message" => "missing_permission"));
    }


    public function treeGetChildsByIdAction()
    {

        $object = Object_Abstract::getById($this->_getParam("node"));
        $object->getPermissionsForUser($this->getUser());

        if ($object->hasChilds()) {

            $limit = intval($this->_getParam("limit"));
            if (!$this->_getParam("limit")) {
                $limit = 100000000;
            }
            $offset = intval($this->_getParam("start"));


            $childsList = new Object_List();
            $condition = "o_parentId = '" . $object->getId() . "'";

            // custom views start
            if ($this->_getParam("view")) {
                $cvConfig = Pimcore_Tool::getCustomViewConfig();
                $cv = $cvConfig[($this->_getParam("view") - 1)];

                if ($cv["classes"]) {
                    $cvConditions = array();
                    $cvClasses = explode(",", $cv["classes"]);
                    foreach ($cvClasses as $cvClass) {
                        $cvConditions[] = "o_classId = '" . $cvClass . "'";
                    }

                    $cvConditions[] = "o_type = 'folder'";

                    if (count($cvConditions) > 0) {
                        $condition .= " AND (" . implode(" OR ", $cvConditions) . ")";
                    }
                }
            }
            // custom views end

            $childsList->setCondition($condition);
            $childsList->setLimit($limit);
            $childsList->setOffset($offset);
            $childsList->setOrderKey("o_key");
            $childsList->setOrder("asc");

            $childs = $childsList->load();

            foreach ($childs as $child) {

                $tmpObject = $this->getTreeNodeConfig($child);

                if ($child->isAllowed("list")) {
                    $objects[] = $tmpObject;
                }
            }
        }

        if ($this->_getParam("limit")) {
            $this->_helper->json(array(
                                      "total" => $object->getChildAmount(),
                                      "nodes" => $objects
                                 ));
        }
        else {
            $this->_helper->json($objects);
        }

    }

    public function getRequiresDependenciesAction()
    {
        $id = $this->_getParam("id");
        $object = Object_Abstract::getById($id);
        if ($object instanceof Object_Abstract) {
            $dependencies = Element_Service::getRequiresDependenciesForFrontend($object->getDependencies());
            $this->_helper->json($dependencies);
        }
        $this->_helper->json(false);
    }

    public function getRequiredByDependenciesAction()
    {
        $id = $this->_getParam("id");
        $object = Object_Abstract::getById($id);
        if ($object instanceof Object_Abstract) {
            $dependencies = Element_Service::getRequiredByDependenciesForFrontend($object->getDependencies());
            $this->_helper->json($dependencies);
        }
        $this->_helper->json(false);
    }

    public function getTreePermissionsAction()
    {


        $this->removeViewRenderer();
        $user = User::getById($this->_getParam("user"));

        if ($this->_getParam("xaction") == "update") {
            $data = json_decode($this->_getParam("data"));
            if (!empty($data->id)) {
                $nodes[] = $data;
            } else {
                $nodes = $data;
            }
            //loop through store nodes  = objects to edit
            if (is_array($nodes)) {

                foreach ($nodes as $node) {
                    $object = Object_Abstract::GetById($node->id);
                    $parent = Object_Abstract::getById($object->getParentId());
                    $objectPermission = $object->getPermissionsForUser($user);
                    if ($objectPermission instanceof Object_Permissions) {
                        $found = true;
                        if (!$node->permissionSet) {
                            //reset permission by deleting it
                            $objectPermission->delete();
                            $permissions = $object->getPermissions();
                            break;

                        } else {

                            if ($objectPermission->getCid() != $object->getId() or $objectPermission->getUser()->getId() != $user->getId()) {
                                //we got a parent's permission create new permission
                                //or we got a usergroup permission, create a new permission for specific user
                                $objectPermission = new Object_Permissions();
                                $objectPermission->setUser($user);
                                $objectPermission->setUserId($user->getId());
                                $objectPermission->setUsername($user->getUsername());
                                $objectPermission->setCid($object->getId());
                                $objectPermission->setCpath($object->getFullPath());
                            }

                            //update object_permission
                            $permissionNames = $objectPermission->getValidPermissionKeys();
                            foreach ($permissionNames as $name) {
                                //check if parent allows list
                                if ($parent) {
                                    $parent->getPermissionsForUser($user);
                                    $parentList = $parent->isAllowed("list");
                                } else {
                                    $parentList = true;
                                }
                                $setterName = "set" . ucfirst($name);

                                if (isset($node->$name) and $node->$name and $parentList) {
                                    $objectPermission->$setterName(true);
                                } else if (isset($node->$name)) {
                                    $objectPermission->$setterName(false);
                                    //if no list permission set all to false
                                    if ($name == "list") {

                                        foreach ($permissionNames as $n) {
                                            $setterName = "set" . ucfirst($n);
                                            $objectPermission->$setterName(false);
                                        }
                                        break;
                                    }
                                }
                            }

                            $objectPermission->save();

                            if ($node->evictChildrenPermissions) {

                                $successorList = new Object_List();
                                $successorList->setOrderKey("o_key");
                                $successorList->setOrder("asc");
                                if ($object->getParentId() < 1) {
                                    $successorList->setCondition("o_parentId > 0");

                                } else {
                                    $successorList->setCondition("o_path like '" . $object->getFullPath() . "/%'");
                                }

                                $successors = $successorList->load();
                                foreach ($successors as $successor) {

                                    $permission = $successor->getPermissionsForUser($user);
                                    if ($permission->getId() > 0 and $permission->getCid() == $successor->getId()) {
                                        $permission->delete();

                                    }
                                }
                            }
                        }
                    }
                }
                $this->_helper->json(array(
                                          "success" => true
                                     ));
            }
        } else if ($this->_getParam("xaction") == "destroy") {
            //ignore
        } else {

            //read
            if ($user instanceof User) {
                $userPermissionsNamespace = new Zend_Session_Namespace('objectUserPermissions');
                if (!isset($userPermissionsNamespace->expandedNodes) or $userPermissionsNamespace->currentUser != $user->getId()) {
                    $userPermissionsNamespace->currentUser = $user->getId();
                    $userPermissionsNamespace->expandedNodes = array();
                }
                if (is_numeric($this->_getParam("anode")) and $this->_getParam("anode") > 0) {
                    $node = $this->_getParam("anode");
                    $object = Object_Abstract::getById($node);
                    $objects = array();
                    if ($user instanceof User and $object->hasChilds()) {

                        $total = $object->getChildAmount();
                        $list = new Object_List();
                        $list->setCondition("o_parentId = '" . $object->getId() . "'");
                        $list->setOrderKey("o_key");
                        $list->setOrder("asc");

                        $childsList = $list->load();
                        $requestedNodes = array();
                        foreach ($childsList as $childObject) {
                            $requestedNodes[] = $childObject->getId();
                        }

                        $userPermissionsNamespace->expandedNodes = array_merge($userPermissionsNamespace->expandedNodes, $requestedNodes);
                    }
                } else {
                    $userPermissionsNamespace->expandedNodes = array_merge($userPermissionsNamespace->expandedNodes, array(1));
                }


                //load all nodes which are open in client
                $objectList = new Object_List();
                $objectList->setOrderKey("o_key");
                $objectList->setOrder("asc");
                $queryIds = "'" . implode("','", $userPermissionsNamespace->expandedNodes) . "'";
                $objectList->setCondition("o_id in (" . $queryIds . ")");
                $o = $objectList->load();
                $total = count($o);
                foreach ($o as $object) {
                    if ($object->getParentId() > 0) {
                        $parent = Object_Abstract::getById($object->getParentId());
                    } else $parent = null;

                    // get current user permissions
                    $object->getPermissionsForUser($this->getUser());
                    // only display object if listing is allowed for the current user
                    if ($object->isAllowed("list") and $object->isAllowed("permissions")) {
                        $treeNodePermissionConfig = $this->getTreeNodePermissionConfig($user, $object, $parent, true);
                        $objects[] = $treeNodePermissionConfig;
                        $tmpObjects[$object->getId()] = $treeNodePermissionConfig;
                    }
                }


                //only visible nodes and in the order how they should be displayed ... doesn't make sense but seems to fix bug of duplicate nodes
                $objectsForFrontend = array();
                $visible = $this->_getParam("visible");
                if ($visible) {
                    $visibleNodes = explode(",", $visible);
                    foreach ($visibleNodes as $nodeId) {
                        $objectsForFrontend[] = $tmpObjects[$nodeId];
                        if ($nodeId == $this->_getParam("anode") and is_array($requestedNodes)) {
                            foreach ($requestedNodes as $nId) {
                                $objectsForFrontend[] = $tmpObjects[$nId];
                            }
                        }
                    }
                    $objects = $objectsForFrontend;
                }


            }
            $this->_helper->json(array(
                                      "total" => $total,
                                      "data" => $objects,
                                      "success" => true
                                 ));


        }

    }


    public function treeGetRootAction()
    {

        $id = 1;
        if ($this->_getParam("id")) {
            $id = intval($this->_getParam("id"));
        }

        $root = Object_Abstract::getById($id);
        $root->getPermissionsForUser($this->getUser());

        if ($root->isAllowed("list")) {
            $this->_helper->json($this->getTreeNodeConfig($root));
        }

        $this->_helper->json(array("success" => false, "message" => "missing_permission"));
    }

    /**
     * @param  User $user
     * @param  Object_Abstract $child
     * @param  Object_Abstract $parent
     * @param  boolean $expanded
     * @return
     */
    protected function getTreeNodePermissionConfig($user, $child, $parent, $expanded)
    {

        $userGroup = $user->getParent();
        if ($userGroup instanceof User) {
            $child->getPermissionsForUser($userGroup);

            $lock_list = $child->isAllowed("list");
            $lock_view = $child->isAllowed("view");
            $lock_save = $child->isAllowed("save");
            $lock_publish = $child->isAllowed("publish");
            $lock_unpublish = $child->isAllowed("unpublish");
            $lock_delete = $child->isAllowed("delete");
            $lock_rename = $child->isAllowed("rename");
            $lock_create = $child->isAllowed("create");
            $lock_permissions = $child->isAllowed("permissions");
            $lock_settings = $child->isAllowed("settings");
            $lock_versions = $child->isAllowed("versions");
            $lock_properties = $child->isAllowed("properties");
            $lock_properties = $child->isAllowed("properties");
        }


        if ($parent instanceof Object_Abstract) {
            $parent->getPermissionsForUser($user);
        }
        $objectPermissions = $child->getPermissionsForUser($user);

        $generallyAllowed = $user->isAllowed("objects");
        $parentId = (int)$child->getParentId();
        $parentAllowedList = true;
        if ($parent instanceof Object_Abstract) {
            $parentAllowedList = $parent->isAllowed("list") and $generallyAllowed;
        }

        $listAllowed = $child->isAllowed("list");

        $child->getPermissionsForUser($user);
        $tmpObject = array(
            "_parent" => $parentId > 0 ? $parentId : null,
            "_id" => (int)$child->getId(),
            "text" => $child->getKey(),
            "type" => $child->getType(),
            "path" => $child->getFullPath(),
            "basePath" => $child->getPath(),
            "elementType" => "object",
            "permissionSet" => $objectPermissions->getId() > 0 and $objectPermissions->getCid() === $child->getId(),
            "list" => $listAllowed,
            "list_editable" => $parentAllowedList and $generallyAllowed and !$lock_list and !$user->isAdmin(),
            "view" => $child->isAllowed("view"),
            "view_editable" => $listAllowed and $generallyAllowed and !$lock_view and !$user->isAdmin(),
            "save" => $child->isAllowed("save"),
            "save_editable" => $listAllowed and $generallyAllowed and !$lock_save and !$user->isAdmin(),
            "publish" => $child->isAllowed("publish"),
            "publish_editable" => $listAllowed and $generallyAllowed and !$lock_publish and !$user->isAdmin(),
            "unpublish" => $child->isAllowed("unpublish"),
            "unpublish_editable" => $listAllowed and $generallyAllowed and !$lock_unpublish and !$user->isAdmin(),
            "delete" => $child->isAllowed("delete"),
            "delete_editable" => $listAllowed and $generallyAllowed and !$lock_delete and !$user->isAdmin(),
            "rename" => $child->isAllowed("rename"),
            "rename_editable" => $listAllowed and $generallyAllowed and !$lock_rename and !$user->isAdmin(),
            "create" => $child->isAllowed("create"),
            "create_editable" => $listAllowed and $generallyAllowed and !$lock_create and !$user->isAdmin(),
            "permissions" => $child->isAllowed("permissions"),
            "permissions_editable" => $listAllowed and $generallyAllowed and !$lock_permissions and !$user->isAdmin(),
            "settings" => $child->isAllowed("settings"),
            "settings_editable" => $listAllowed and $generallyAllowed and !$lock_settings and !$user->isAdmin(),
            "versions" => $child->isAllowed("versions"),
            "versions_editable" => $listAllowed and $generallyAllowed and !$lock_versions and !$user->isAdmin(),
            "properties" => $child->isAllowed("properties"),
            "properties_editable" => $listAllowed and $generallyAllowed and !$lock_properties and !$user->isAdmin()

        );

        $tmpObject["expanded"] = $expanded;
        $tmpObject["_is_leaf"] = $child->hasNoChilds();
        $tmpObject["iconCls"] = "pimcore_icon_object";


        if ($child->getType() == "folder") {
            $tmpObject["iconCls"] = "pimcore_icon_folder";
            $tmpObject["qtipCfg"] = array(
                "title" => "ID: " . $child->getId()
            );
        }
        else {
            $tmpObject["className"] = $child->getClass()->getName();
            $tmpObject["qtipCfg"] = array(
                "title" => "ID: " . $child->getId(),
                "text" => 'Type: ' . $child->getClass()->getName()
            );

            if (!$child->isPublished()) {
                $tmpObject["cls"] = "pimcore_unpublished";
            }
            if ($child->getClass()->getIcon()) {
                unset($tmpObject["iconCls"]);
                $tmpObject["icon"] = $child->getClass()->getIcon();
            }
        }

        return $tmpObject;
    }

    protected function getTreeNodeConfig($child)
    {


        $tmpObject = array(
            "id" => $child->getId(),
            "text" => $child->getKey(),
            "type" => $child->getType(),
            "path" => $child->getFullPath(),
            "basePath" => $child->getPath(),
            "elementType" => "object",
            "locked" => $child->isLocked(),
            "lockOwner" => $child->getLocked() ? true : false
        );

        $tmpObject["isTarget"] = false;
        $tmpObject["allowDrop"] = false;
        $tmpObject["allowChildren"] = false;

        $tmpObject["leaf"] = $child->hasNoChilds();
        $tmpObject["iconCls"] = "pimcore_icon_object";

        $tmpObject["isTarget"] = true;
        $tmpObject["allowDrop"] = true;
        $tmpObject["allowChildren"] = true;
        $tmpObject["leaf"] = false;
        $tmpObject["cls"] = "";

        if ($child->getType() == "folder") {
            $tmpObject["iconCls"] = "pimcore_icon_folder";
            $tmpObject["qtipCfg"] = array(
                "title" => "ID: " . $child->getId()
            );
        }
        else {
            $tmpObject["published"] = $child->isPublished();
            $tmpObject["className"] = $child->getClass()->getName();
            $tmpObject["qtipCfg"] = array(
                "title" => "ID: " . $child->getId(),
                "text" => 'Type: ' . $child->getClass()->getName()
            );

            if (!$child->isPublished()) {
                $tmpObject["cls"] .= "pimcore_unpublished ";
            }
            if ($child->getClass()->getIcon()) {
                unset($tmpObject["iconCls"]);
                $tmpObject["icon"] = $child->getClass()->getIcon();
            }
        }

        $tmpObject["expanded"] = $child->hasNoChilds();
        $tmpObject["permissions"] = $child->getUserPermissions($this->getUser());


        if ($child->isLocked()) {
            $tmpObject["cls"] .= "pimcore_treenode_locked ";
        }
        if ($child->getLocked()) {
            $tmpObject["cls"] .= "pimcore_treenode_lockOwner ";
        }

        return $tmpObject;
    }

    public function getAction()
    {

        // check for lock
        if (Element_Editlock::isLocked($this->_getParam("id"), "object")) {
            $this->_helper->json(array(
                                      "editlock" => Element_Editlock::getByElement($this->_getParam("id"), "object")
                                 ));
        }
        Element_Editlock::lock($this->_getParam("id"), "object");

        $object = Object_Abstract::getById(intval($this->_getParam("id")));

        // set the latest available version for editmode
        $object = $this->getLatestVersion($object);

        $object->getPermissionsForUser($this->getUser());

        if ($object->isAllowed("view")) {

            $objectData = array();

            $objectData["idPath"] = Pimcore_Tool::getIdPathForElement($object);
            $objectData["layout"] = $object->getClass()->getLayoutDefinitions();
            $objectData["data"] = array();
            foreach ($object->getClass()->getFieldDefinitions() as $key => $def) {
                if ($object->$key !== null) {
                    $objectData["data"][$key] = $def->getDataForEditmode($object->$key);
                } else if (method_exists($def, "getLazyLoading") and $def->getLazyLoading()) {

                    //lazy loading data is fetched from DB differently, so that not every relation object is instantiated
                    if ($def->isRemoteOwner()) {
                        $refKey = $def->getOwnerFieldName();
                        $refId = $def->getOwnerClassId();
                    } else $refKey = $key;
                    $relations = $object->getRelationData($refKey, !$def->isRemoteOwner(), $refId);
                    $data = array();

                    if ($def instanceof Object_Class_Data_Href) {
                        $data = $relations[0];
                    } else {
                        foreach ($relations as $rel) {
                            if ($def instanceof Object_Class_Data_Objects) {
                                $data[] = array($rel["id"], $rel["path"], $rel["subtype"]);
                            } else {
                                $data[] = array($rel["id"], $rel["path"], $rel["type"], $rel["subtype"]);
                            }
                        }
                    }
                    $objectData["data"][$key] = $data;
                }
            }

            $objectData["general"] = array();
            $allowedKeys = array("o_published", "o_key", "o_id", "o_modificationDate", "o_classId", "o_className", "o_locked");

            foreach (get_object_vars($object) as $key => $value) {
                if (strstr($key, "o_") && in_array($key, $allowedKeys)) {
                    $objectData["general"][$key] = $value;
                }
            }

            $objectData["properties"] = Element_Service::minimizePropertiesForEditmode($object->getProperties());
            //$objectData["permissions"] = $object->getO_permissions();
            $objectData["userPermissions"] = $object->getUserPermissions($this->getUser());
            $objectData["versions"] = $object->getVersions();
            $objectData["scheduledTasks"] = $object->getScheduledTasks();
            $objectData["allowedClasses"] = $object->getClass();
            if ($object instanceof Object_Concrete) {
                $objectData["lazyLoadedFields"] = $object->getLazyLoadedFields();
            }

            $this->_helper->json($objectData);
        }
        else {
            Logger::debug(get_class($this) . ": prevented getting object id [ " . $object->getId() . " ] because of missing permissions");
            $this->_helper->json(array("success" => false, "message" => "missing_permission"));
        }


    }


    public function lockAction()
    {
        $object = Object_Abstract::getById($this->_getParam("id"));
        if ($object instanceof Object_Abstract) {
            $object->setO_locked((bool)$this->_getParam("locked"));
            //TODO: if latest version published - publish
            //if latest version not published just save new version

        }
    }

    public function getFolderAction()
    {

        // check for lock
        if (Element_Editlock::isLocked($this->_getParam("id"), "object")) {
            $this->_helper->json(array(
                                      "editlock" => Element_Editlock::getByElement($this->_getParam("id"), "object")
                                 ));
        }
        Element_Editlock::lock($this->_getParam("id"), "object");

        $object = Object_Abstract::getById(intval($this->_getParam("id")));
        $object->getPermissionsForUser($this->getUser());

        if ($object->isAllowed("view")) {

            $objectData = array();

            $objectData["general"] = array();

            $allowedKeys = array("o_published", "o_key", "o_id");
            foreach (get_object_vars($object) as $key => $value) {
                if (strstr($key, "o_") && in_array($key, $allowedKeys)) {
                    $objectData["general"][$key] = $value;
                }
            }

            $objectData["properties"] = Element_Service::minimizePropertiesForEditmode($object->getProperties());
            //$objectData["permissions"] = $object->getO_permissions();
            $objectData["userPermissions"] = $object->getUserPermissions($this->getUser());
            $objectData["classes"] = $object->getResource()->getClasses();

            $this->_helper->json($objectData);
        }
        else {
            Logger::debug(get_class($this) . ": prevented getting folder id [ " . $object->getId() . " ] because of missing permissions");
            $this->_helper->json(array("success" => false, "message" => "missing_permission"));
        }


    }


    public function addAction()
    {

        $success = false;

        $className = "Object_" . ucfirst($this->_getParam("className"));

        $parent = Object_Abstract::getById($this->_getParam("parentId"));
        $parent->getPermissionsForUser($this->getUser());

        if ($parent->isAllowed("create")) {
            $intendedPath = $parent->getFullPath() . "/" . $this->_getParam("key");
            $equalObject = Object_Abstract::getByPath($intendedPath);

            if ($equalObject == null) {

                $object = new $className();
                $object->setClassId($this->_getParam("classId"));
                $object->setClassName($this->_getParam("className"));
                $object->setParentId($this->_getParam("parentId"));
                $object->setKey($this->_getParam("key"));
                $object->setCreationDate(time());
                $object->setUserOwner($this->getUser()->getId());
                $object->setUserModification($this->getUser()->getId());
                $object->setPublished(false);

                try {

                    $object->save();
                    $success = true;
                } catch (Exception $e) {
                    $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
                }

            } else {

                Logger::debug(get_class($this) . ": prevented creating object because object with same path+key [ $intendedPath ] already exists");
            }
        } else {
            Logger::debug(get_class($this) . ": prevented adding object because of missing permissions");
        }

        if ($success) {
            $this->_helper->json(array(
                                      "success" => $success,
                                      "id" => $object->getId(),
                                      "type" => $object->getType()
                                 ));
        }
        else {
            $this->_helper->json(array(
                                      "success" => $success
                                 ));
        }
    }

    public function addFolderAction()
    {
        $success = false;

        $parent = Object_Abstract::getById($this->_getParam("parentId"));
        $parent->getPermissionsForUser($this->getUser());

        if ($parent->isAllowed("create")) {

            $equalObject = Object_Abstract::getByPath($parent->getFullPath() . "/" . $this->_getParam("key"));

            if (!$equalObject) {
                $folder = Object_Folder::create(array(
                                                     "o_parentId" => $this->_getParam("parentId"),
                                                     "o_creationDate" => time(),
                                                     "o_userOwner" => $this->user->getId(),
                                                     "o_userModification" => $this->user->getId(),
                                                     "o_key" => $this->_getParam("key"),
                                                     "o_published" => true
                                                ));

                $folder->setCreationDate(time());
                $folder->setUserOwner($this->getUser()->getId());
                $folder->setUserModification($this->getUser()->getId());

                try {
                    $folder->save();
                    $success = true;
                } catch (Exception $e) {
                    $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
                }
            }
        }
        else {
            Logger::debug(get_class($this) . ": prevented creating object id because of missing permissions");
        }

        $this->_helper->json(array("success" => $success));
    }

    public function deleteAction()
    {
        $object = Object_Abstract::getById($this->_getParam("id"));
        $object->getPermissionsForUser($this->getUser());

        if ($object->isAllowed("delete")) {
            Element_Recyclebin_Item::create($object, $this->getUser());
            $object->delete();

            $this->_helper->json(array("success" => true));
        }

        $this->_helper->json(array("success" => false, "message" => "missing_permission"));
    }

    public function hasDependenciesAction()
    {

        $hasDependency = false;

        try {
            $object = Object_Abstract::getById($this->_getParam("id"));
            $hasDependency = $object->getDependencies()->isRequired();
        }
        catch (Exception $e) {
            logger::err("Admin_ObjectController->hasDependenciesAction: failed to access object with id: " . $this->_getParam("id"));
        }

        // check for childs
        if (!$hasDependency && $object instanceof Object_Abstract) {
            $hasDependency = $object->hasChilds();
        }

        $this->_helper->json(array(
                                  "hasDependencies" => $hasDependency
                             ));
    }


    public function updateAction()
    {

        $success = false;
        $allowUpdate = true;

        $object = Object_Abstract::getById($this->_getParam("id"));
        $object->getPermissionsForUser($this->getUser());

        if ($object->isAllowed("settings")) {

            $values = Zend_Json::decode($this->_getParam("values"));

            if ($values["key"]) {
                $object->setKey($values["key"]);
            }

            if ($values["parentId"]) {
                $parent = Object_Abstract::getById($values["parentId"]);

                //check if parent is changed
                if ($object->getParentId() != $parent->getId()) {

                    $objectWithSamePath = Object_Abstract::getByPath($parent->getFullPath() . "/" . $object->getKey());

                    if ($objectWithSamePath != null) {
                        $allowUpdate = false;
                    }
                }

                //$object->setO_path($newPath);
                $object->setParentId($values["parentId"]);


            }

            if (array_key_exists("locked", $values)) {
                $object->setLocked($values["locked"]);
            }

            if ($allowUpdate) {
                $object->setModificationDate(time());
                $object->setUserModification($this->getUser()->getId());

                try {
                    $object->save();
                    $success = true;
                } catch (Exception $e) {
                    $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
                }
            }
            else {
                Logger::debug(get_class($this) . ": prevented move of object, object with same path+key alredy exists in this location.");
            }
        }
        else {
            Logger::debug(get_class($this) . ": prevented update object because of missing permissions.");
        }

        $this->_helper->json(array("success" => $success));
    }


    public function saveAction()
    {

        $object = Object_Abstract::getById($this->_getParam("id"));

        // set the latest available version for editmode
        $object = $this->getLatestVersion($object);

        $objectUserPermissions = $object->getPermissionsForUser($this->getUser());

        $object->setUserModification($this->getUser()->getId());

        // data
        if ($this->_getParam("data")) {

            $data = Zend_Json::decode($this->_getParam("data"));
            foreach ($data as $key => $value) {

                $fd = $object->getClass()->getFieldDefinition($key);
                if ($fd) {
                    if (method_exists($fd, "isRemoteOwner") and $fd->isRemoteOwner()) {
                        $relations = $object->getRelationData($fd->getOwnerFieldName(), false, null);
                        $toAdd = $this->detectAddedRemoteOwnerRelations($relations, $value);
                        $toDelete = $this->detectDeletedRemoteOwnerRelations($relations, $value);
                        if (count($toAdd) > 0 or count($toDelete) > 0) {
                            $this->processRemoteOwnerRelations($object, $toDelete, $toAdd, $fd->getOwnerFieldName());
                        }
                    } else {
                        $object->setValue($key, $fd->getDataFromEditmode($value));
                    }
                }
            }
        }

        // general settings
        if ($this->_getParam("general")) {
            $general = Zend_Json::decode($this->_getParam("general"));
            $object->setValues($general);

        }

        $object = $this->assignPropertiesFromEditmode($object);


        // scheduled tasks
        if ($this->_getParam("scheduler")) {
            $tasks = array();
            $tasksData = Zend_Json::decode($this->_getParam("scheduler"));

            if (!empty($tasksData)) {
                foreach ($tasksData as $taskData) {
                    $taskData["date"] = strtotime($taskData["date"] . " " . $taskData["time"]);

                    $task = new Schedule_Task($taskData);
                    $tasks[] = $task;
                }
            }

            $object->setScheduledTasks($tasks);
        }

        if ($this->_getParam("task") == "unpublish") {
            $object->setPublished(false);
        }
        if ($this->_getParam("task") == "publish") {
            $object->setPublished(true);
        }


        if (($this->_getParam("task") == "publish" && $object->isAllowed("publish")) or ($this->_getParam("task") == "unpublish" && $object->isAllowed("unpublish"))) {

            try {
                $object->save();
                $this->_helper->json(array("success" => true));
            } catch (Exception $e) {
                Logger::log($e);
                $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
            }


        }
        else {
            if ($object->isAllowed("save")) {
                try {
                    $object->saveVersion();
                    $this->_helper->json(array("success" => true));
                } catch (Exception $e) {
                    Logger::log($e);
                    $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
                }
            }
        }


    }

    public function saveFolderAction()
    {

        $object = Object_Abstract::getById($this->_getParam("id"));
        $object->getPermissionsForUser($this->getUser());

        // general settings
        $general = Zend_Json::decode($this->_getParam("general"));
        $object->setValues($general);
        $object->setUserModification($this->getUser()->getId());

        $object = $this->assignPropertiesFromEditmode($object);

        if ($object->isAllowed("publish")) {
            try {

                // grid config
                $gridConfig = Zend_Json::decode($this->_getParam("gridconfig"));
                $configFile = PIMCORE_CONFIGURATION_DIRECTORY . "/object/grid/" . $object->getId() . ".psf";
                $configDir = dirname($configFile);
                if (!is_dir($configDir)) {
                    mkdir($configDir, 0755, true);
                }
                file_put_contents($configFile, serialize($gridConfig));


                $object->save();
                $this->_helper->json(array("success" => true));
            } catch (Exception $e) {
                $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
            }
        }

        $this->_helper->json(array("success" => false, "message" => "missing_permission"));
    }

    protected function assignPropertiesFromEditmode($object)
    {

        if ($this->_getParam("properties")) {
            $properties = array();
            // assign inherited properties
            foreach ($object->getProperties() as $p) {
                if ($p->isInherited()) {
                    $properties[$p->getName()] = $p;
                }
            }

            $propertiesData = Zend_Json::decode($this->_getParam("properties"));

            if (is_array($propertiesData)) {
                foreach ($propertiesData as $propertyName => $propertyData) {

                    $value = $propertyData["data"];


                    try {
                        $property = new Property();
                        $property->setType($propertyData["type"]);
                        $property->setName($propertyName);
                        $property->setCtype("object");
                        $property->setDataFromEditmode($value);
                        $property->setInheritable($propertyData["inheritable"]);

                        $properties[$propertyName] = $property;
                    }
                    catch (Exception $e) {
                        logger::err("Can't add " . $propertyName . " to object " . $object->getFullPath());
                    }
                }
            }
            $object->setProperties($properties);
        }

        return $object;
    }


    public function getPredefinedPropertiesAction()
    {

        $list = new Property_Predefined_List();
        $list->setCondition("ctype = 'object'");
        $list->load();

        $properties = array();
        foreach ($list->getProperties() as $type) {
            $properties[] = $type;
        }

        $this->_helper->json(array("properties" => $properties));
    }

    public function deleteVersionAction()
    {
        $version = Version::getById($this->_getParam("id"));
        $version->delete();

        $this->_helper->json(array("success" => true));
    }

    public function publishVersionAction()
    {

        $version = Version::getById($this->_getParam("id"));
        $object = $version->loadData();

        $currentObject = Object_Abstract::getById($object->getId());
        $currentObject->getPermissionsForUser($this->getUser());
        if ($currentObject->isAllowed("publish")) {
            $object->setPublished(true);
            try {
                $object->save();
                $this->_helper->json(array("success" => true));
            } catch (Exception $e) {
                $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
            }
        }

        $this->_helper->json(array("success" => false, "message" => "missing_permission"));
    }

    public function previewVersionAction()
    {
        $version = Version::getById($this->_getParam("id"));
        $object = $version->loadData();

        $this->view->object = $object;
    }

    public function diffVersionsAction()
    {
        $version1 = Version::getById($this->_getParam("from"));
        $object1 = $version1->loadData();

        $version2 = Version::getById($this->_getParam("to"));
        $object2 = $version2->loadData();

        $this->view->object1 = $object1;
        $this->view->object2 = $object2;
    }

    public function getVersionsAction()
    {
        if ($this->_getParam("id")) {
            $object = Object_Abstract::getById($this->_getParam("id"));
            $versions = $object->getVersions();

            $this->_helper->json(array("versions" => $versions));
        }
    }

    public function gridGetColumnConfigAction()
    {

        if ($this->_getParam("id")) {
            $class = Object_Class::getById($this->_getParam("id"));
        } else if ($this->_getParam("name")) {
            $class = Object_Class::getByName($this->_getParam("name"));
        }

        $fields = $class->getFieldDefinitions();

        $types = array();
        if ($this->_getParam("types")) {
            $types = explode(",", $this->_getParam("types"));
        }

        // grid config
        $gridConfig = array();
        if ($this->_getParam("objectId")) {
            $configFile = PIMCORE_CONFIGURATION_DIRECTORY . "/object/grid/" . $this->_getParam("objectId") . ".psf";
            if (is_file($configFile)) {
                $gridConfig = unserialize(file_get_contents($configFile));
            }
        }

        $availableFields = array();
        $count = 0;
        foreach ($fields as $key => $field) {

            if ($field instanceof Object_Class_Data_Localizedfields) {
                foreach ($field->getFieldDefinitions() as $fd) {
                    if (empty($types) || in_array($fd->getFieldType(), $types)) {
                        $fd->setNoteditable(true);
                        $fieldConfig = $this->getFieldGridConfig($fd, $gridConfig);
                        $availableFields[] = $fieldConfig;
                    }
                }
            } else {
                if (empty($types) || in_array($field->getFieldType(), $types)) {
                    $fieldConfig = $this->getFieldGridConfig($field, $gridConfig);
                    $availableFields[] = $fieldConfig;
                }
            }

            $count++;
        }

        usort($availableFields, function ($a, $b)
        {
            if ($a["position"] == $b["position"]) {
                return 0;
            }
            return ($a["position"] < $b["position"]) ? -1 : 1;
        });

        $this->_helper->json($availableFields);
    }

    protected function getFieldGridConfig($field, $gridConfig)
    {

        $key = $field->getName();
        $config = null;
        $title = $field->getName();
        if (method_exists($field, "getTitle")) {
            if ($field->getTitle()) {
                $title = $field->getTitle();
            }
        }

        if ($field->getFieldType() == "select" || $field->getFieldType() == "country" || $field->getFieldType() == "language" || $field->getFieldType() == "user") {
            $config["store"] = $field->getOptions();
        }
        if ($field->getFieldType() == "slider") {
            $config["minValue"] = $field->getMinValue();
            $config["maxValue"] = $field->getMaxValue();
            $config["increment"] = $field->getIncrement();
        }

        if (method_exists($field, "getWidth")) {
            $config["width"] = $field->getWidth();
        }
        if (method_exists($field, "getHeight")) {
            $config["height"] = $field->getHeight();
        }

        $index = $count;
        $gridVisibility = $field->getVisibleGridView();
        if ($gridConfig["columns"]) {
            if ($gridConfig["columns"][$key]) {
                $index = (int)$gridConfig["columns"][$key]["position"];
                $gridVisibility = !(bool)$gridConfig["columns"][$key]["hidden"];
            }
        }

        return array(
            "key" => $key,
            "type" => $field->getFieldType(),
            "label" => $title,
            "config" => $config,
            "layout" => $field,
            "position" => $index,
            "visibleGridView" => $gridVisibility,
            "visibleSearch" => $field->getVisibleSearch()
        );
    }

    public function exportAction()
    {

        $folder = Object_Abstract::getById($this->_getParam("folderId"));
        $class = Object_Class::getById($this->_getParam("classId"));

        $className = $class->getName();

        $listClass = "Object_" . ucfirst($className) . "_List";

        if ($this->_getParam("filter")) {
            $conditionFilters = Object_Service::getFilterCondition($this->_getParam("filter"), $class);
        }
        if ($this->_getParam("condition")) {
            $conditionFilters = " AND (" . $this->_getParam("condition") . ")";
        }

        $list = new $listClass();
        $list->setCondition("o_path LIKE '" . $folder->getFullPath() . "%'" . $conditionFilters);
        $list->setOrder("ASC");
        $list->setOrderKey("o_id");
        $list->load();

        $objects = array();
        logger::debug("objects in list:" . count($list->getObjects()));
        foreach ($list->getObjects() as $object) {

            if ($object instanceof Object_Concrete) {
                $o = $this->csvObjectData($object);
                $objects[] = $o;
            }
        }
        //create csv
        $columns = array_keys($objects[0]);
        foreach ($columns as $key => $value) {
            $columns[$key] = '"' . $value . '"';
        }
        $csv = implode(";", $columns) . "\r\n";
        foreach ($objects as $o) {
            foreach ($o as $key => $value) {

                //clean value of evil stuff such as " and linebreaks
                if (is_string($value)) {
                    $value = strip_tags($value);
                    $value = str_replace('"', '', $value);
                    $value = str_replace("\r", "", $value);
                    $value = str_replace("\n", "", $value);

                    $o[$key] = '"' . $value . '"';
                }
            }
            $csv .= implode(";", $o) . "\r\n";
        }
        header("Content-type: text/csv");
        header("Content-Disposition: attachment; filename=\"export.csv\"");
        echo $csv;
        exit;
    }

    public function gridProxyAction()
    {

        if ($this->_getParam("data")) {

            if ($this->_getParam("xaction") == "destroy") {

                // currently not supported

                /*$id = Zend_Json::decode($this->_getParam("data"));
 
                $object = Object_Abstract::getById($id);
                $object->delete();
 
                $this->_helper->json(array("success" => true, "data" => array()));
                */
            }
            else if ($this->_getParam("xaction") == "update") {

                $data = Zend_Json::decode($this->_getParam("data"));

                // save glossary
                $object = Object_Abstract::getById($data["id"]);

                $object->setValues($data);

                try {
                    $object->save();
                    $this->_helper->json(array("data" => Object_Service::gridObjectData($object), "success" => true));
                } catch (Exception $e) {
                    $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
                }


            }
            else if ($this->_getParam("xaction") == "create") {
                // currently not supported

                /*
                $object = new Object_Abstract();
                $this->_helper->json(array("data" => Object_Service::gridObjectData($object), "success" => true));
                */
            }

        } else {
            // get list of objects
            $folder = Object_Abstract::getById($this->_getParam("folderId"));
            $class = Object_Class::getById($this->_getParam("classId"));
            $className = $class->getName();

            $start = 0;
            $limit = 20;
            $orderKey = "o_id";
            $order = "ASC";

            if ($this->_getParam("limit")) {
                $limit = $this->_getParam("limit");
            }
            if ($this->_getParam("start")) {
                $start = $this->_getParam("start");
            }
            if ($this->_getParam("sort")) {
                if ($this->_getParam("sort") == "fullpath") {
                    $orderKey = array("o_path", "o_key");
                } else if ($this->_getParam("sort") == "id") {
                    $orderKey = "o_id";
                } else if ($this->_getParam("sort") == "published") {
                    $orderKey = "o_published";
                } else if ($this->_getParam("sort") == "modificationDate") {
                    $orderKey = "o_modificationDate";
                } else if ($this->_getParam("sort") == "creationDate") {
                    $orderKey = "o_creationDate";
                } else {
                    $orderKey = $this->_getParam("sort");
                }
            }
            if ($this->_getParam("dir")) {
                $order = $this->_getParam("dir");
            }

            $listClass = "Object_" . ucfirst($className) . "_List";

            // create filter condition
            if ($this->_getParam("filter")) {
                $conditionFilters = Object_Service::getFilterCondition($this->_getParam("filter"), $class);
            }
            if ($this->_getParam("condition")) {
                $conditionFilters = " AND (" . $this->_getParam("condition") . ")";
            }

            $list = new $listClass();
            $list->setCondition("o_path LIKE '" . $folder->getFullPath() . "%'" . $conditionFilters);
            $list->setLimit($limit);
            $list->setOffset($start);
            $list->setOrder($order);
            $list->setOrderKey($orderKey);
            $list->setIgnoreLocale(true);

            $list->load();

            $objects = array();
            foreach ($list->getObjects() as $object) {

                $o = Object_Service::gridObjectData($object);

                $objects[] = $o;
            }

            $this->_helper->json(array("data" => $objects, "success" => true, "total" => $list->getTotalCount()));
        }


    }


    /**
     * Flattens object data to an array with key=>value where
     * value is simply a string representation of the value (for objects, hrefs and assets the full path is used)
     *
     * @param Object_Abstract $object
     * @return array
     */
    protected function csvObjectData($object)
    {

        $o = array();
        foreach ($object->getClass()->getFieldDefinitions() as $key => $value) {
            //exclude remote owner fields
            if (!($value instanceof Object_Class_Data_Relations_Abstract and $value->isRemoteOwner())) {
                $o[$key] = $value->getForCsvExport($object);
            }

        }

        $o["id (system)"] = $object->getId();
        $o["key (system)"] = $object->getKey();
        $o["fullpath (system)"] = $object->getFullPath();
        $o["published (system)"] = $object->isPublished();

        return $o;
    }


    public function copyAction()
    {
        $success = false;
        $sourceId = intval($this->_getParam("sourceId"));
        $targetId = intval($this->_getParam("targetId"));

        $target = Object_Abstract::getById($targetId);

        $target->getPermissionsForUser($this->getUser());
        if ($target->isAllowed("create")) {
            $source = Object_Abstract::getById($sourceId);
            if ($source != null) {
                try {
                    if ($this->_getParam("type") == "recursive") {
                        $this->_objectService->copyRecursive($target, $source);
                    }
                    else if ($this->_getParam("type") == "child") {
                        $this->_objectService->copyAsChild($target, $source);
                    }
                    else if ($this->_getParam("type") == "replace") {
                        $this->_objectService->copyContents($target, $source);
                    }

                    $success = true;
                } catch (Exception $e) {
                    logger::err($e);
                    $success = true;
                }
            }
            else {
                Logger::error(get_class($this) . ": could not execute copy/paste, source object with id [ $sourceId ] not found");
                $this->_helper->json(array("success" => false, "message" => "source object not found"));
            }
        }

        $this->_helper->json(array("success" => $success));
    }

    public function getBatchJobsAction()
    {

        $folder = Object_Abstract::getById($this->_getParam("folderId"));
        $class = Object_Class::getById($this->_getParam("classId"));

        if ($this->_getParam("filter")) {
            $conditionFilters = Object_Service::getFilterCondition($this->_getParam("filter"), $class);
        }
        if ($this->_getParam("condition")) {
            $conditionFilters = " AND (" . $this->_getParam("condition") . ")";
        }
        $className = $class->getName();
        $listClass = "Object_" . ucfirst($className) . "_List";
        $list = new $listClass();
        $list->setCondition("o_path LIKE '" . $folder->getFullPath() . "%'" . $conditionFilters);
        $list->setOrder("ASC");
        $list->setOrderKey("o_id");
        $objects = $list->load();
        $jobs = array();
        if (count($objects)>0) {

            foreach ($objects as $o) {
                $jobs[] = $o->getId();
            }
        }

        $this->_helper->json(array("success"=>true,"jobs"=>$jobs));

    }

    public function batchAction()
    {

        $success = true;

        try {
                $object = Object_Abstract::getById($this->_getParam("job"));

                if ($object) {
                    $className = $object->getO_className();
                    $class = Object_Class::getByName($className);
                    $field = $class->getFieldDefinition($this->_getParam("name"));
                    $value = $this->_getParam("value");
                    if ($this->_getParam("valueType") == "object") {
                        $value = Zend_Json::decode($value);
                    }
                    $object->setValue($this->_getParam("name"), $field->getDataFromEditmode($value));
                    try {
                        $object->save();
                        $success = true;
                    } catch (Exception $e) {
                        $this->_helper->json(array("success" => false, "message" => $e->getMessage()));
                    }
                }
                else {
                    logger::debug("ObjectController::batchAction => There is no object left to update.");
                    $success = false;
                }

        }
        catch (Exception $e) {
            $success = false;
            logger::err($e);
        }

        $this->_helper->json(array("success" => $success));
    }



    /**
     * CUSTOM VIEWS
     */
    public function saveCustomviewsAction()
    {

        $success = true;

        $settings = array("views" => array("view" => array()));

        for ($i = 0; $i < 1000; $i++) {
            if ($this->_getParam("name_" . $i)) {

                // check for root-folder
                $rootfolder = "/";
                if ($this->_getParam("rootfolder_" . $i)) {
                    $rootfolder = $this->_getParam("rootfolder_" . $i);
                }

                $settings["views"]["view"][] = array(
                    "name" => $this->_getParam("name_" . $i),
                    "condition" => $this->_getParam("condition_" . $i),
                    "icon" => $this->_getParam("icon_" . $i),
                    "id" => ($i + 1),
                    "rootfolder" => $rootfolder,
                    "showroot" => ($this->_getParam("showroot_" . $i) == "true") ? true : false,
                    "classes" => $this->_getParam("classes_" . $i)
                );
            }
        }


        $config = new Zend_Config($settings, true);
        $writer = new Zend_Config_Writer_Xml(array(
                                                  "config" => $config,
                                                  "filename" => PIMCORE_CONFIGURATION_DIRECTORY . "/customviews.xml"
                                             ));
        $writer->write();


        $this->_helper->json(array("success" => $success));
    }

    public function getCustomviewsAction()
    {

        $data = Pimcore_Tool::getCustomViewConfig();

        $this->_helper->json(array(
                                  "success" => true,
                                  "data" => $data
                             ));
    }


    /**
     * IMPORTER
     */

    public function importUploadAction()
    {

        //copy($_FILES["Filedata"]["tmp_name"],PIMCORE_SYSTEM_TEMP_DIRECTORY."/import_".$this->_getParam("id"));

        $data = file_get_contents($_FILES["Filedata"]["tmp_name"]);

        $encoding = Pimcore_Tool_Text::detectEncoding($data);
        if ($encoding) {
            $data = iconv($encoding, "UTF-8", $data);
        }

        file_put_contents(PIMCORE_SYSTEM_TEMP_DIRECTORY . "/import_" . $this->_getParam("id"), $data);
        file_put_contents(PIMCORE_SYSTEM_TEMP_DIRECTORY . "/import_" . $this->_getParam("id") . "_original", $data);

        $this->_helper->json(array(
                                  "success" => true
                             ));
    }

    public function importGetFileInfoAction()
    {

        $success = true;
        $typeMapping = array(
            "xls" => "xls",
            "xlsx" => "xls",
            "csv" => "csv"
        );

        $supportedFieldTypes = array("checkbox", "country", "date", "datetime", "href", "image", "input", "language", "table", "multiselect", "numeric", "password", "select", "slider", "textarea", "wysiwyg", "objects", "multihref", "geopoint", "geopolygon", "geobounds", "link", "user");

        $file = PIMCORE_SYSTEM_TEMP_DIRECTORY . "/import_" . $this->_getParam("id");
        $type = $typeMapping[$this->_getParam("type")];

        // unsupported filetype
        if (!$type) {
            $success = false;
        }

        if ($type == "csv") {

            // determine type
            $dialect = Pimcore_Tool_Admin::determineCsvDialect(PIMCORE_SYSTEM_TEMP_DIRECTORY . "/import_" . $this->_getParam("id") . "_original");

            $count = 0;
            if (($handle = fopen($file, "r")) !== false) {
                while (($rowData = fgetcsv($handle, 10000, $dialect->delimiter, $dialect->quotechar, $dialect->escapechar)) !== false) {
                    if ($count == 0) {
                        $firstRowData = $rowData;
                    }
                    $tmpData = array();
                    foreach ($rowData as $key => $value) {
                        $tmpData["field_" . $key] = $value;
                    }
                    $data[] = $tmpData;
                    $cols = count($rowData);

                    $count++;

                    if ($count > 18) {
                        break;
                    }

                }
                fclose($handle);
            }
        }


        // get class data
        $class = Object_Class::getById($this->_getParam("classId"));
        $fields = $class->getFieldDefinitions();

        $availableFields = array();

        foreach ($fields as $key => $field) {

            $config = null;
            $title = $field->getName();
            if (method_exists($field, "getTitle")) {
                if ($field->getTitle()) {
                    $title = $field->getTitle();
                }
            }

            if (in_array($field->getFieldType(), $supportedFieldTypes)) {
                $availableFields[] = array($field->getName(), $title . "(" . $field->getFieldType() . ")");
            }
        }

        $mappingStore = array();
        for ($i = 0; $i < $cols; $i++) {

            $mappedField = null;
            if ($availableFields[$i]) {
                $mappedField = $availableFields[$i][0];
            }

            $firstRow = $i;
            if (is_array($firstRowData)) {
                $firstRow = $firstRowData[$i];
                if (strlen($firstRow) > 40) {
                    $firstRow = substr($firstRow, 0, 40) . "...";
                }
            }

            $mappingStore[] = array(
                "source" => $i,
                "firstRow" => $firstRow,
                "target" => $mappedField
            );
        }

        $this->_helper->json(array(
                                  "success" => $success,
                                  "dataPreview" => $data,
                                  "dataFields" => array_keys($data[0]),
                                  "targetFields" => $availableFields,
                                  "mappingStore" => $mappingStore,
                                  "rows" => count(file($file)),
                                  "cols" => $cols,
                                  "type" => $type
                             ));
    }

    public function importProcessAction()
    {

        $success = true;

        $type = $this->_getParam("type");
        $parentId = $this->_getParam("parentId");
        $job = $this->_getParam("job");
        $id = $this->_getParam("id");
        $mappingRaw = Zend_Json::decode($this->_getParam("mapping"));
        $class = Object_Class::getById($this->_getParam("classId"));
        $skipFirstRow = $this->_getParam("skipHeadRow") == "true";
        $fields = $class->getFieldDefinitions();

        $file = PIMCORE_SYSTEM_TEMP_DIRECTORY . "/import_" . $id;

        if ($type == "csv") {

            // determine type
            $dialect = Pimcore_Tool_Admin::determineCsvDialect(PIMCORE_SYSTEM_TEMP_DIRECTORY . "/import_" . $id . "_original");

            $count = 0;
            if (($handle = fopen($file, "r")) !== false) {
                $data = fgetcsv($handle, 1000, $dialect->delimiter, $dialect->quotechar, $dialect->escapechar);
            }
            if ($skipFirstRow && $job == 1) {
                //read the next row, we need to skip the head row
                $data = fgetcsv($handle, 1000, $dialect->delimiter, $dialect->quotechar, $dialect->escapechar);
            }

            $tmpFile = $file . "_tmp";
            $tmpHandle = fopen($tmpFile, "w+");
            while (!feof($handle)) {
                $buffer = fgets($handle);
                fwrite($tmpHandle, $buffer);
            }

            fclose($handle);
            fclose($tmpHandle);

            unlink($file);
            rename($tmpFile, $file);
        }

        // prepare mapping
        foreach ($mappingRaw as $map) {

            if ($map[0] !== "" && $map[1] && !empty($map[2])) {
                $mapping[$map[2]] = $map[0];
            } else if ($map[1] == "published (system)") {
                $mapping["published"] = $map[0];
            }
        }

        // create new object
        $className = "Object_" . ucfirst($this->_getParam("className"));

        $parent = Object_Abstract::getById($this->_getParam("parentId"));
        $parent->getPermissionsForUser($this->getUser());

        $objectKey = "object_" . $job;
        if ($this->_getParam("filename") == "id") {
            $objectKey = null;
        }
        else if ($this->_getParam("filename") != "default") {
            $objectKey = Pimcore_File::getValidFilename($data[$this->_getParam("filename")]);
        }

        $overwrite = false;
        if ($this->_getParam("overwrite") == "true") {
            $overwrite = true;
        }

        if ($parent->isAllowed("create")) {

            $intendedPath = $parent->getFullPath() . "/" . $objectKey;

            if ($overwrite) {
                $object = Object_Abstract::getByPath($intendedPath);
                if (!$object instanceof Object_Concrete) {
                    //create new object
                    $object = new $className();
                } else if (object instanceof Object_Concrete and $object->getO_className() !== $className) {
                    //delete the old object it is of a different class
                    $object->delete();
                    $object = new $className();
                } else if (object instanceof Object_Folder) {
                    //delete the folder
                    $object->delete();
                    $object = new $className();
                } else {
                    //use the existing object
                }
            } else {
                $counter = 1;
                while (Object_Abstract::getByPath($intendedPath) != null) {
                    $objectKey .= "_" . $counter;
                    $intendedPath = $parent->getFullPath() . "/" . $objectKey;
                    $counter++;
                }
                $object = new $className();
            }
            $object->setClassId($this->_getParam("classId"));
            $object->setClassName($this->_getParam("className"));
            $object->setParentId($this->_getParam("parentId"));
            $object->setKey($objectKey);
            $object->setCreationDate(time());
            $object->setUserOwner($this->getUser()->getId());
            $object->setUserModification($this->getUser()->getId());

            if ($data[$mapping["published"]] === "1") {
                $object->setPublished(true);
            } else {
                $object->setPublished(false);
            }

            foreach ($class->getFieldDefinitions() as $key => $field) {

                $value = $data[$mapping[$key]];
                if (array_key_exists($key, $mapping) and  $value != null) {
                    // data mapping
                    $value = $field->getFromCsvImport($value);

                    if ($value !== null) {
                        $object->setValue($key, $value);
                    }
                }
            }

            try {
                $object->save();
                $this->_helper->json(array("success" => true));
            } catch (Exception $e) {
                $this->_helper->json(array("success" => false, "message" => $object->getKey() . " - " . $e->getMessage()));
            }
        }


        $this->_helper->json(array("success" => $success));
    }


    /**
     * @param  Object_Concrete $object
     * @param  array $toDelete
     * @param  array $toAdd
     * @param  string $ownerFieldName
     * @return void
     */
    protected function  processRemoteOwnerRelations($object, $toDelete, $toAdd, $ownerFieldName)
    {

        $getter = "get" . ucfirst($ownerFieldName);
        $setter = "set" . ucfirst($ownerFieldName);

        foreach ($toDelete as $id) {

            $owner = Object_Abstract::getById($id);
            //TODO: lock ?!
            if (method_exists($owner, $getter)) {
                $currentData = $owner->$getter();
                if (is_array($currentData)) {
                    for ($i = 0; $i < count($currentData); $i++) {
                        if ($currentData[$i]->getId() == $object->getId()) {
                            unset($currentData[$i]);
                            $owner->$setter($currentData);
                            $owner->save();
                            Logger::debug("Saved object id [ " . $owner->getId() . " ] by remote modification through [" . $object->getId() . "], Action: deleted [ " . $object->getId() . " ] from [ $ownerFieldName]");
                            break;
                        }
                    }
                }
            }
        }


        foreach ($toAdd as $id) {
            $owner = Object_Abstract::getById($id);
            //TODO: lock ?!
            if (method_exists($owner, $getter)) {
                $currentData = $owner->$getter();
                $currentData[] = $object;

                $owner->$setter($currentData);
                $owner->save();
                Logger::debug("Saved object id [ " . $owner->getId() . " ] by remote modification through [" . $object->getId() . "], Action: added [ " . $object->getId() . " ] to [ $ownerFieldName ]");
            }
        }
    }

    /**
     * @param  array $relations
     * @param  array $value
     * @return array
     */
    protected function detectDeletedRemoteOwnerRelations($relations, $value)
    {
        $originals = array();
        $changed = array();
        foreach ($relations as $r) {
            $originals[] = $r["dest_id"];
        }
        if (is_array($value)) {
            foreach ($value as $row) {
                $changed[] = $row['id'];
            }
        }
        $diff = array_diff($originals, $changed);
        return $diff;
    }

    /**
     * @param  array $relations
     * @param  array $value
     * @return array
     */
    protected function detectAddedRemoteOwnerRelations($relations, $value)
    {
        $originals = array();
        $changed = array();
        foreach ($relations as $r) {
            $originals[] = $r["dest_id"];
        }
        if (is_array($value)) {
            foreach ($value as $row) {
                $changed[] = $row['id'];
            }
        }
        $diff = array_diff($changed, $originals);
        return $diff;
    }

    /**
     * @param  Object_Concrete $object
     * @return Object_Concrete
     */
    protected function getLatestVersion(Object_Concrete $object)
    {
        $modificationDate = $object->getModificationDate();
        $latestVersion = $object->getLatestVersion();
        if ($latestVersion) {
            $latestObj = $latestVersion->loadData();
            if ($latestObj instanceof Object_Concrete) {
                $object = $latestObj;
                $object->setModificationDate($modificationDate); // set de modification-date from published version to compare it in js-frontend
            }
        }
        return $object;
    }
}