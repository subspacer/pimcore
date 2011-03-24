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

require_once 'Zend/Controller/Plugin/Abstract.php';

class Pimcore_Controller_Plugin_CssMinify extends Zend_Controller_Plugin_Abstract {

    protected $enabled = true;

    public function routeStartup(Zend_Controller_Request_Abstract $request) {

        $conf = Zend_Registry::get("pimcore_config_system");
        if (!$conf->outputfilters) {
            return $this->disable();
        }

        if (!$conf->outputfilters->cssminify) {
            return $this->disable();
        }

    }

    public function disable() {
        $this->enabled = false;
        return true;
    }

    public function dispatchLoopShutdown() {
        
        if(!Pimcore_Tool::isHtmlResponse($this->getResponse())) {
            return;
        }
        
        if ($this->enabled) {
            include_once("simple_html_dom.php");
            
            $body = $this->getResponse()->getBody();
            
            $html = str_get_html($body);
            $styles = $html->find("link[rel=stylesheet], style[type=text/css]");
            
            $stylesheetContent = "";
            
            foreach ($styles as $style) {
                if($style->tag == "style") {
                    $stylesheetContent .= $style->innertext;
                }
                else {
                    if($style->media == "screen" || !$style->media) {
                        $source = $style->href;
                        $path = "";
                        if (is_file(PIMCORE_ASSET_DIRECTORY . $source)) {
                            $path = PIMCORE_ASSET_DIRECTORY . $source;
                        }
                        else if (is_file(PIMCORE_DOCUMENT_ROOT . $source)) {
                            $path = PIMCORE_DOCUMENT_ROOT . $source;
                        }
    
                        if (is_file("file://".$path)) {
                            $stylesheetContent .= file_get_contents($path);
                            $style->outertext = "";
                        }
                    }
                }
            }
                        
            
            if(strlen($stylesheetContent) > 1) {
                $stylesheetPath = PIMCORE_TEMPORARY_DIRECTORY."/minified_css_".md5($stylesheetContent).".css";
                
                if(!is_file($stylesheetPath)) {
                    $stylesheetContent = Minify_CSS::minify($stylesheetContent);
                    
                    // put minified contents into one single file
                    file_put_contents($stylesheetPath, $stylesheetContent);
                }
                
                $head = $html->find("head",0);
                $head->innertext = $head->innertext . "\n" . '<link rel="stylesheet" media="screen" type="text/css" href="' . str_replace(PIMCORE_DOCUMENT_ROOT,"",$stylesheetPath) . '" />'."\n";
            }
            
            $body = $html->save();
            $this->getResponse()->setBody($body);
        }
    }
}
