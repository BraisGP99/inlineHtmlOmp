<?php

/**
 * @file plugins/generic/inlineHtmlOmp/InlineHtmlOmpPlugin.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file htmls/COPYING.
 *
 * @class InlineHtmlOmpPlugin
 *
 * @brief Class for inlineHtmlOmp plugin
 */
    namespace APP\plugins\generic\inlineHtmlOmp;

    use APP\core\Application;
    use APP\template\TemplateManager;
    use DOMdocument;
    use PKP\plugins\Hook;
    use APP\plugins\generic\htmlMonographFile\htmlMonographFilePlugin;

class InlineHtmlOmpPlugin extends htmlMonographFilePlugin {


    /**
     * @copyhtml Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId); 
        if (!$success) return false; 
        if ($success && $this->getEnabled()) {
                Hook::add('CatalogBookHandler::view', [$this, 'viewCallback']);
        }
         
        return false;
    }


    /**
     * Install default settings on press creation.
     *
     * @return string
     */
    public function getContextSpecificPluginSettingsFile()
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * Get the display name of this plugin.
     *
     * @return string
     */
    public function getDisplayName()
    {
        return __('plugins.generic.inlineHtmlOmp.displayName');
    }

    /**
     * Get a description of the plugin.
     */
    public function getDescription()
    {
        return __('plugins.generic.inlineHtmlOmp.description');
    }


public function viewCallback($hookName, $args) {
       
        $request = Application::get()->getRequest();
        $submission =& $args[1];        
        $publicationFormat =& $args[2];
        $submissionFile =& $args[3];
        $inline =& $args[4];
        $mimetype = $submissionFile->getData('mimetype');
        if($mimetype=="text/html"){
            $templateMgr = TemplateManager::getManager($request);
            $html = parent::_getHTMLContents($request, $submission, $publicationFormat, $submissionFile);
            error_log($html);
            $htmlNonJs = $this->_checkScript($html,$templateMgr);


            $templateMgr->assign('fileHtml', $htmlNonJs);
            $templateMgr->display($this->getTemplateResource('displayInline.tpl'));
        }

        return true;
    }
   

    private function _checkScript($html){
        
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $scripts = $doc->getElementsByTagName('script');

        for ($i = $scripts->length - 1; $i >= 0; $i--) {
            $script = $scripts->item($i);
            $outerHTML = $doc->saveHTML($script);
            $comentario = $doc
            ->createComment($outerHTML);
            $script->parentNode->replaceChild($comentario, $script);
        }

            return $doc->saveHTML();
    }
}

