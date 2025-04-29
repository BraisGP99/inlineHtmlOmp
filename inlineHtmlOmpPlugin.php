<?php

/**
 * @file plugins/generic/inlineHtmlOmp/InlineHtmlOmpPlugin.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class InlineHtmlOmpPlugin
 *
 * @brief Class for inlineHtmlOmp plugin
 */
    namespace APP\plugins\generic\inlineHtmlOmp;

    use APP\core\Application;
    use DAORegistry;
    use APP\core\Request;
    use APP\core\Services;
    use APP\facades\Repo;
    use APP\file\PublicFileManager;
    use APP\publication\Publication;
    use APP\publicationFormat\PublicationFormat;
    use APP\submission\Submission;
    use APP\template\TemplateManager;
    use DOMDocument;
    use PKP\plugins\Hook;
    use PKP\submissionFile\SubmissionFile;
    use PKP\config\Config;
    use APP\plugins\generic\htmlMonographFile\HtmlMonographFilePlugin;
use Error;

class InlineHtmlOmpPlugin extends HtmlMonographFilePlugin {

    private $htmlBody = null;

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                Hook::add('CatalogBookHandler::view', [$this, 'viewCallback']);
                Hook::add('Templates::Catalog::Book::Main',[$this, 'addHtml']);
            }
            return true;
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

    if($hookName == "CatalogBookHandler::view"){
        $request = Application::get()->getRequest();
        $submission =& $args[1];        
        $publicationFormat =& $args[2];
        $submissionFile =& $args[3];
        $submissionId = $submission->getId();
        $contextId = $submission->getContextId();
        $mimetype = $submissionFile->getData('mimetype');

        if($submissionFile && $mimetype==="text/html"){
            $filePublication = null;
            foreach ($submission->getData('publications') as $publication) {
                if ($publication->getId() === $publicationFormat->getData('publicationId')) {
                    $filePublication = $publication;
                    break;
                }
            }
            $templateMgr = TemplateManager::getManager($request);
            $body = $this->loadHtmlBody($request,$submission,$publicationFormat,$submissionFile);
            $templateMgr->assign('fileBody',$body);

        }
        return false;
    }
   

}

// TODO check htmlBody assignment

    private function loadHtmlBody($request, $submission, $publicationFormat, $submissionFile) {
        $html = parent::_getHTMLContents($request, $submission, $publicationFormat, $submissionFile);
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);

        $doc->loadHTML($html);

        $body = '';
        if ($doc->getElementsByTagName('body')->length) {
            $bodyElement = $doc->getElementsByTagName('body')->item(0);
            foreach ($bodyElement->childNodes as $childNode) {
                $body .= $doc->saveHTML($childNode);
            }
        }

        $this->htmlBody = $body;
}

    public function addHtml($hookName, $args){
      $request = Application::get()->getRequest();

      error_log('Valor de htmlBody: ' . var_export($this->htmlBody, true));

      if(!$this->htmlBody) return false;
      $templateMgr =& TemplateManager::getManager($request);
      $output =& $args[2];
      
      $templateMgr->assign('fileBody',$this->htmlBody);

      $output .= $templateMgr->fetch($this->getTemplateResource('displayInline.tpl'));

        return true;
    }
}
        