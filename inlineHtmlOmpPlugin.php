<?php

/**
 * @file plugins/generic/inlineHtmlOmp/InlineHrmlOmpPlugin.php
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

class InlineHtmlOmpPlugin extends HtmlMonographFilePlugin {

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
                Hook::add('TemplateResource::getFilename',[$this, '_overridePluginTemplates']);
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

    /**
     * Callback to view the HTML content rather than downloading.
     *
     * @param string $hookName
     *
     * @return bool
     */
    public function viewCallback($hookName, $params)
    {
        $submission = & $params[1];
        $publicationFormat = & $params[2];
        $submissionFile = & $params[3];
        $inline = & $params[4];
        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);

        $mimetype = $submissionFile->getData('mimetype');
        // echo(error_log($mimetype));

        if ($submissionFile && $mimetype == 'text/html') {
            /** @var ?Publication */
            $filePublication = null;
            foreach ($submission->getData('publications') as $publication) {
                if ($publication->getId() === $publicationFormat->getData('publicationId')) {
                    $filePublication = $publication;
                    break;
                }
            }
            // echo(error_log(print_r($filePublication)));

            $html =  $this->_getHTMLContents($request, $submission, $publicationFormat, $submissionFile);
            $doc = new DOMDocument();
            libxml_use_internal_errors(true);

            if (Config::getVar('i18n', 'client_charset') === "utf-8"){
                $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            }
            else{
                     $doc->loadHTML($html);
            }


            $body = "";
            if($doc->getElementsByTagName("body")->length != 0){
                $bodyElement = $doc->getElementsByTagName("body")->item(0);
                foreach($bodyElement->childNodes as $childNode){
                        $body .= $doc->saveHTML($childNode);
                }
            }

            // error_log($body);

            if($doc->getElementsByTagName("head")->length != 0){
                 $head = $doc->getElementsByTagName("head")->item(0);
                if($head->getElementsByTagName("link")->length != 0){
                    $links = $head->getElementsByTagName("link");
                    $count = 0;
                    foreach($links as $link){
                        $templateMgr->addHeader('embedStylesheet' . $count . '' , '<link rel="stylesheet" type="text/css" href=""' . $link->getAttribute("href") . '">"');
                        $count++;
                    }
                }
            }

            if ($head->getElementsByTagName('script')->length != 0) {
                $scripts = $head->getElementsByTagName("script");
                $count = 0;
                foreach($scripts as $script) {
                    if (stristr($script->getAttribute("src"), '.js')) {
                        $templateMgr->addHeader('embedJs'. $count .'', '<script type="text/javascript" src="' . $script->getAttribute("src") . '"></script>');
                        $count++;
                    }
                }
            } else {
                $body = $doc->saveHTML();
            }
            $templateMgr->assign('fileBody',$body);
            $templateMgr->assign([
                'pluginUrl' => $request->getBaseUrl() . '/' . $this->getPluginPath(),
                'monograph' => $submission,
                'publicationFormat' => $publicationFormat,
                'downloadFile' => $submissionFile,
                'isLatestPublication' => $submission->getData('currentPublicationId') === $publicationFormat->getData('publicationId'),
                'filePublication' => $filePublication,
            ]);
            // Hook::call('HtmlMonographFilePlugin::monographDownloadFinished',[&$returner]);
            $templateMgr->display($this->getTemplateResource('displayInline.tpl'));
            return true;
        }

        return false;
    }


     /**
     * Return string containing the contents of the HTML file.
     * This function performs any necessary filtering, like image URL replacement.
     *
     * @param Request $request
     * @param Submission $monograph
     * @param PublicationFormat $publicationFormat
     * @param SubmissionFile $submissionFile
     *
     * @return string
     */
    public function _getHTMLContents($request, $monograph, $publicationFormat, $submissionFile)
    {
        $contents = Services::get('file')->fs->read($submissionFile->getData('path'));

        // Replace media file references
        $proofCollector = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$monograph->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PROOF]);

        $dependentCollector = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$monograph->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_DEPENDENT])
            ->filterByAssoc(
                Application::ASSOC_TYPE_SUBMISSION_FILE,
                [$submissionFile->getId()]
            );

        $embeddableFiles = array_merge(
            $proofCollector->getMany()->toArray(),
            $dependentCollector->getMany()->toArray()
        );

        foreach ($embeddableFiles as $embeddableFile) {
            $fileUrl = $request->url(null, 'catalog', 'download', [$monograph->getBestId(), 'version', $publicationFormat->getData('publicationId'), $publicationFormat->getBestId(), $embeddableFile->getBestId()], ['inline' => true]);
            $pattern = preg_quote($embeddableFile->getLocalizedData('name'), '/');

            $contents = preg_replace(
                '/([Ss][Rr][Cc]|[Hh][Rr][Ee][Ff]|[Dd][Aa][Tt][Aa])\s*=\s*"([^"]*' . $pattern . ')"/',
                '\1="' . $fileUrl . '"',
                $contents
            );

            // Replacement for Flowplayer
            $contents = preg_replace(
                '/[Uu][Rr][Ll]\s*\:\s*\'(' . $pattern . ')\'/',
                'url:\'' . $fileUrl . '\'',
                $contents
            );

            // Replacement for other players (tested with odeo; yahoo and google player won't work w/ OJS URLs, might work for others)
            $contents = preg_replace(
                '/[Uu][Rr][Ll]=([^"]*' . $pattern . ')/',
                'url=' . $fileUrl,
                $contents
            );
        }

        // Perform replacement for ojs://... URLs
        $contents = preg_replace_callback(
            '/(<[^<>]*")[Oo][Mm][Pp]:\/\/([^"]+)("[^<>]*>)/',
            [&$this, '_handleOmpUrl'],
            $contents
        );

        $templateMgr = TemplateManager::getManager($request);
        $contents = $templateMgr->loadHtmlGalleyStyles($contents, $embeddableFiles);
        
        // Perform variable replacement for press, publication format, site info
        $press = $request->getPress();
        $site = $request->getSite();

        $paramArray = [
            'pressTitle' => $press->getLocalizedName(),
            'siteTitle' => $site->getLocalizedTitle(),
            'currentUrl' => $request->getRequestUrl()
        ];

        foreach ($paramArray as $key => $value) {
            $contents = str_replace('{$' . $key . '}', $value, $contents);
        }

        return $contents;
    }
     public $count = 0;
     public function _overridePluginTemplates($hookName,$args){
        
         $template = $args[0];
         error_log($template." -> template");
         error_log($this->count);
         $this->count++;
         $templateMgr = $args[1];
         if($template === 'templates/frontend/pages/book.tpl'){
                error_log($template." -> monograph! ");
            $templateMgr->display($this->getTemplateResource('displayInline.tpl'));
          }
         return false;
     }
    
    public function _handleOmpUrl($matchArray)
    {
        $request = Application::get()->getRequest();
        $url = $matchArray[2];
        $anchor = null;
        if (($i = strpos($url, '#')) !== false) {
            $anchor = substr($url, $i + 1);
            $url = substr($url, 0, $i);
        }
        $urlParts = explode('/', $url);
        if (isset($urlParts[0])) {
            switch (strtolower_codesafe($urlParts[0])) {
                case 'press':
                    $url = $request->url(
                        $urlParts[1] ?? $request->getRouter()->getRequestedContextPath($request),
                        null,
                        null,
                        null,
                        null,
                        $anchor
                    );
                    break;
                case 'monograph':
                    if (isset($urlParts[1])) {
                        $url = $request->url(
                            null,
                            'catalog',
                            'book',
                            $urlParts[1],
                            null,
                            $anchor
                        );
                    }
                    break;
                case 'sitepublic':
                    array_shift($urlParts);
                    $publicFileManager = new PublicFileManager();
                    $url = $request->getBaseUrl() . '/' . $publicFileManager->getSiteFilesPath() . '/' . implode('/', $urlParts) . ($anchor ? '#' . $anchor : '');
                    break;
                case 'public':
                    array_shift($urlParts);
                    $press = $request->getPress();
                    $publicFileManager = new PublicFileManager();
                    $url = $request->getBaseUrl() . '/' . $publicFileManager->getContextFilesPath($press->getId()) . '/' . implode('/', $urlParts) . ($anchor ? '#' . $anchor : '');
                    break;
            }
        }
        return $matchArray[1] . $url . $matchArray[3];
    }
}