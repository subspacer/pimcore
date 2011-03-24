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
 * @category   Pimcore
 * @package    Object_Class
 * @copyright  Copyright (c) 2009-2010 elements.at New Media Solutions GmbH (http://www.elements.at)
 * @license    http://www.pimcore.org/license     New BSD License
 */

class Object_Class_Resource_Mysql extends Pimcore_Model_Resource_Mysql_Abstract {

    
    protected $_sqlChangeLog = array();
    
    /**
     * Contains all valid columns in the database table
     *
     * @var array
     */
    protected $validColumns = array();

    /**
     * Get the valid columns from the database
     *
     * @return void
     */
    public function init() {
        $this->validColumns = $this->getValidTableColumns("classes");
    }

    /**
     * Get the data for the object from database for the given id, or from the ID which is set in the object
     *
     * @param integer $id
     * @return void
     */
    public function getById($id = null) {
        if (!$id) {
            $id = $this->model->getId();
        }

        $classRaw = $this->db->fetchRow("SELECT * FROM classes WHERE id='" . $id . "'");
        $this->assignVariablesToModel($classRaw);
        
        $this->model->setPropertyVisibility(unserialize($classRaw["propertyVisibility"]));
        $this->model->setLayoutDefinitions($this->getLayoutData());


    }

    /**
     * Get the data for the object from database for the given name, or from the name which is set in the object
     *
     * @param string $name
     * @return void
     */
    public function getByName($name = null) {
        if (!$name) {
            $name = $this->model->getName();
        }

        $classRaw = $this->db->fetchRow("SELECT * FROM classes WHERE name='" . $name . "'");
        $this->assignVariablesToModel($classRaw);

        $this->model->setPropertyVisibility(unserialize($classRaw["propertyVisibility"]));
        $this->model->setLayoutDefinitions($this->getLayoutData());
    }
    
    /**
     * Save object to database
     *
     * @return mixed
     */
    protected function getLayoutData () {
        $file = PIMCORE_CLASS_DIRECTORY."/definition_". $this->model->getId() .".psf";
        if(is_file($file)) {
            return unserialize(file_get_contents($file));
        }
        return;
    }


    /**
     * Save object to database
     *
     * @return void
     */
    public function save() {
        if ($this->model->getId()) {
            return $this->update();
        }
        return $this->create();
    }

    /**
     * Save changes to database, it's an good idea to use save() instead
     *
     * @return void
     */
    public function update() {

        $class = get_object_vars($this->model);

        foreach ($class as $key => $value) {
            if (in_array($key, $this->validColumns)) {

                if (is_array($value) || is_object($value)) {
                    $value = serialize($value);
                } else  if(is_bool($value)) {
                    $value = (int)$value;
                }
                $data[$key] = $value;
            }
        }

        $this->db->update("classes", $data, "id = '" . $this->model->getId() . "'");
        // only for logging
        $setsql = array();
        foreach ($data as $key => $value) {
            $setsql[] = "`" . $key . "` = '" . $value . "'";
        }
        $this->logSql("UPDATE classes SET ". implode(",",$setsql) ." WHERE id = '" . $this->model->getId() . "';");
        
        
         // save definition as a serialized file
        file_put_contents(PIMCORE_CLASS_DIRECTORY."/definition_". $this->model->getId() .".psf", serialize($this->model->layoutDefinitions));
           
                    
        $objectTable = "object_query_" . $this->model->getId();
        $objectDatastoreTable = "object_store_" . $this->model->getId();
        $objectDatastoreTableRelation = "object_relations_" . $this->model->getId();

        $objectView = "object_" . $this->model->getId();

        // create object table if not exists
        $protectedColums = array("oo_id", "oo_classId", "oo_className");
        $protectedDatastoreColumns = array("oo_id");

        $this->dbexec("CREATE TABLE IF NOT EXISTS `" . $objectTable . "` (
			  `oo_id` int(11) NOT NULL default '0',
			  `oo_classId` int(11) default '" . $this->model->getId() . "',
			  `oo_className` varchar(255) default '" . $this->model->getName() . "',
			  PRIMARY KEY  (`oo_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;");

        $this->dbexec("CREATE TABLE IF NOT EXISTS `" . $objectDatastoreTable . "` (
			  `oo_id` int(11) NOT NULL default '0',
			  PRIMARY KEY  (`oo_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;");

        $this->dbexec("CREATE TABLE IF NOT EXISTS `" . $objectDatastoreTableRelation . "` (
			  `src_id` int(11) NOT NULL default '0',
			  `dest_id` int(11) NOT NULL default '0',
			  `type` varchar(20) NOT NULL default '0',
			  `fieldname` varchar(255) NOT NULL default '0',
              `index` int(11) unsigned NOT NULL default '0',
			  PRIMARY KEY  (`src_id`, `dest_id`, `type`, `fieldname`),
              INDEX `index` (`index`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;");


        $existingColumns = $this->getValidTableColumns($objectTable, false); // no caching of table definition
        $existingDatastoreColumns = $this->getValidTableColumns($objectDatastoreTable, false); // no caching of table definition

        $columnsToRemove = $existingColumns;
        $datastoreColumnsToRemove = $existingDatastoreColumns;
        
        // add non existing columns in the table
        if (is_array($this->model->getFieldDefinitions()) && count($this->model->getFieldDefinitions())) {
            foreach ($this->model->getFieldDefinitions() as $key => $value) {
                
                // nullable & default value
                list($defaultvalue, $nullable) = $this->getDefaultValueAndNullableForField($value);

                // if a datafield requires more than one column in the query table
                if (is_array($value->getQueryColumnType())) {
                    foreach ($value->getQueryColumnType() as $fkey => $fvalue) {
                        $this->addModifyColumn($objectTable, $key . "__" . $fkey, $fvalue, $defaultvalue, $nullable);
                        $protectedColums[] = $key . "__" . $fkey;
                    }
                }
                
                // if a datafield requires more than one column in the datastore table => only for non-relation types
                if(!$value->isRelationType() && is_array($value->getColumnType())) {
                    foreach ($value->getColumnType() as $fkey => $fvalue) {
                        $this->addModifyColumn($objectDatastoreTable, $key . "__" . $fkey, $fvalue, $defaultvalue, $nullable);
                        $protectedDatastoreColumns[] = $key . "__" . $fkey;
                    }
                }
                
                // everything else
                if (!is_array($value->getQueryColumnType()) && !is_array($value->getColumnType())) {
                    if ($value->getQueryColumnType()) {
                        $this->addModifyColumn($objectTable, $key, $value->getQueryColumnType(), $defaultvalue, $nullable);
                        $protectedColums[] = $key;
                    }
                    if ($value->getColumnType() && !$value->isRelationType()) {
                        $this->addModifyColumn($objectDatastoreTable, $key, $value->getColumnType(), $defaultvalue, $nullable);
                        $protectedDatastoreColumns[] = $key;
                    }
                }
                
                // add indices
                $this->addIndexToField($value, $objectTable);
                $this->addIndexToField($value, $objectDatastoreTable);
            }
        }
        
        // remove unused columns in the table        
        $this->removeUnusedColumns($objectTable, $columnsToRemove, $protectedColums);
        $this->removeUnusedColumns($objectDatastoreTable, $datastoreColumnsToRemove, $protectedDatastoreColumns, true);

        // create view
        try {
            $this->dbexec('CREATE OR REPLACE VIEW `' . $objectView . '` AS SELECT * FROM `objects` left JOIN `' . $objectTable . '` ON `objects`.`o_id` = `' . $objectTable . '`.`oo_id` WHERE `objects`.`o_classId` = ' . $this->model->getId() . ';');
        }
        catch (Exception $e) {
            Logger::debug($e);
        }
    }
    
    private function removeUnusedColumns ($table, $columnsToRemove, $protectedColumns, $emptyRelations = false) {
        if (is_array($columnsToRemove) && count($columnsToRemove) > 0) {
            foreach ($columnsToRemove as $value) {
                //if (!in_array($value, $protectedColumns)) {
                if (!in_array(strtolower($value), array_map('strtolower', $protectedColumns))) {
                    $this->dbexec('ALTER TABLE `' . $table . '` DROP COLUMN `' . $value . '`;');
                    
                    if($emptyRelations) {
                        $tableRelation = "object_relations_" . $this->model->getId();
                        $this->db->delete($tableRelation, "fieldname = '" . $value . "'");
                        $this->logSql("DELETE FROM ".$tableRelation." WHERE fieldname = '" . $value . "';"); // only for logging
                    }
                }
            }
        }
    }
    
    private function getDefaultValueAndNullableForField ($field) {
        
        $nullable = "NULL";
        if ($field->getMandatory()) {
            $nullable = "NOT NULL";
        }
        
        $defaultvalue = "";
        if (method_exists($field, 'getDefaultValue') && $field->getDefaultValue() !== null) {
            $defaultvalue = " DEFAULT '" . $field->getDefaultValue() . "'";
        } else if (method_exists($field, 'getDefaultValue') && $field->getDefaultValue() === null and $nullable == "NULL"){
            $defaultvalue = " DEFAULT NULL";
        }
        
        return array($defaultvalue, $nullable);
    }
    
    private function addModifyColumn ($table, $colName, $type, $default, $null) {
        
        $existingColumns = $this->getValidTableColumns($table, false);
        $existingColName = null;

        // check for existing column case insensitive eg a rename from myInput to myinput
        $matchingExisting = preg_grep('/^' . preg_quote($colName, '/') . '$/i', $existingColumns);
        if(is_array($matchingExisting) && !empty($matchingExisting)) {
            $existingColName = current($matchingExisting);
        }

        if ($existingColName === null) {
            $this->dbexec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $colName . '` ' . $type . $default . ' ' . $null . ';');
        } else {
            $this->dbexec('ALTER TABLE `' . $table . '` CHANGE COLUMN `' . $existingColName . '` `' . $colName . '` ' . $type . $default . ' ' . $null . ';');
        }
    }
    
    private function addIndexToField ($field, $table) {
        
        if ($field->getIndex()) {
            if (is_array($field->getQueryColumnType())) {
                // multicolumn field
                foreach ($field->getQueryColumnType() as $fkey => $fvalue) {
                    $columnName = $field->getName() . "__" . $fkey;
                    try {
                        $this->dbexec("ALTER TABLE `" . $table . "` ADD INDEX `p_index_" . $columnName . "` (`" . $columnName . "`);");
                    } catch (Exception $e) {}
                }            
            } else {
                // single -column field
                $columnName = $field->getName();
                try {
                    $this->dbexec("ALTER TABLE `" . $table . "` ADD INDEX `p_index_" . $columnName . "` (`" . $columnName . "`);");
                } catch (Exception $e) {}
            }
        } else {
            if (is_array($field->getQueryColumnType())) {
                // multicolumn field
                foreach ($field->getQueryColumnType() as $fkey => $fvalue) {
                    $columnName = $field->getName() . "__" . $fkey;
                    try {
                        $this->dbexec("ALTER TABLE `" . $table . "` DROP INDEX `p_index_" . $columnName . "`;");
                    } catch (Exception $e) {}
                }            
            } else {
                // single -column field
                $columnName = $field->getName();
                try {
                    $this->dbexec("ALTER TABLE `" . $table . "` DROP INDEX `p_index_" . $columnName . "`;");
                } catch (Exception $e) {}
            }
        }
    }
    
    //@TODO exclude in Object_Class_Data
    private function isRelationType($fieldtype) {
        if ($fieldtype == 'multihref' || $fieldtype == 'objects' || $fieldtype == 'href')
            return true;
        return false;
    }

    /**
     * Create a new record for the object in database
     *
     * @return boolean
     */
    public function create() {
        $this->db->insert("classes", array("name" => $this->model->getName()));
        // only for logging
        $this->logSql("INSERT INTO `classes` SET `name`='".$this->model->getName()."';");
        
        $this->model->setId($this->db->lastInsertId());
        $this->model->setCreationDate(time());
        $this->model->setModificationDate(time());

        $this->save();
    }

    /**
     * Deletes object from database
     *
     * @return void
     */
    public function delete() {

        $this->db->delete("classes", "id = '" . $this->model->getId() . "'");
        // only for logging
        $this->logSql("DELETE FROM classes WHERE id = '" . $this->model->getId() . "';");

        $objectTable = "object_query_" . $this->model->getId();
        $objectDatastoreTable = "object_store_" . $this->model->getId();
        $objectDatastoreTableRelation = "object_relations_" . $this->model->getId();
        
        
        $this->dbexec('DROP TABLE `' . $objectTable);
        $this->dbexec('DROP TABLE `' . $objectDatastoreTable);
        $this->dbexec('DROP TABLE `' . $objectDatastoreTableRelation);

        $this->dbexec('DROP VIEW `object_' . $this->model->getId() . '`');
        
        // delete data
        $this->db->delete("objects", "o_classId = '" . $this->model->getId() . "'");
        $this->logSql("DELETE FROM objects WHERE o_classId = '" . $this->model->getId() . "';"); // only for logging
        
        // removefieldcollection tables
        $allTables = $this->db->fetchAll("SHOW TABLES LIKE 'object_collection_%_" . $this->model->getId() . "'");
        $tables = array();
        
        foreach ($allTables as $table) {
            $collectionTable = current($table);
            $this->dbexec("DROP TABLE `".$collectionTable."`");
        }
        
        @unlink(PIMCORE_CLASS_DIRECTORY."/definition_". $this->model->getId() .".psf");
    }

    /**
     * Update the class name in all object
     *
     * @return void
     */
    public function updateClassNameInObjects($newName) {
        $this->db->update("objects", array(
            "o_className" => $newName
        ), "o_classId = '" . $this->model->getId() . "'");
        // only for logging 
        $this->logSql("UPDATE objects SET `o_className` = '".$newName."' WHERE o_classId = '" . $this->model->getId() . "';");
        
        $this->db->update("object_query_" . $this->model->getId(), array(
            "oo_className" => $newName
        ));
        // only for logging
        $this->logSql("UPDATE object_query_" . $this->model->getId() . " SET `oo_className` = '" . $newName . "'");
    }
    
    private function dbexec($sql) {
        $this->db->exec($sql);
        $this->logSql($sql);
    }
    
    private function logSql ($sql) {
        $this->_sqlChangeLog[] = $sql;
    }
    
    public function __destruct () {
        
        // write sql change log for deploying to production system
        if(!empty($this->_sqlChangeLog)) {
            $log = implode("\n\n\n", $this->_sqlChangeLog);
            
            $filename = "db-change-log_".time()."_class-".$this->model->getName().".sql";
            $file = PIMCORE_SYSTEM_TEMP_DIRECTORY."/".$filename;
            if(defined("PIMCORE_DB_CHANGELOG_DIRECTORY")) {
                $file = PIMCORE_DB_CHANGELOG_DIRECTORY."/".$filename;
            }
            
            file_put_contents($file, $log);
        }
    }
}