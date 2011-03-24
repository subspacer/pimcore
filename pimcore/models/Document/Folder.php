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
 * @package    Document
 * @copyright  Copyright (c) 2009-2010 elements.at New Media Solutions GmbH (http://www.elements.at)
 * @license    http://www.pimcore.org/license     New BSD License
 */

class Document_Folder extends Document {

    /**
     * static type of this object
     *
     * @var string
     */
    public $type = "folder";


    /**
     * Get the current name of the class
     *
     * @return string
     */
    public static function getClassName() {
        return __CLASS__;
    }
}