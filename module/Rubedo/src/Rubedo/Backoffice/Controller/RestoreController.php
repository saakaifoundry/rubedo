<?php
/**
 * Rubedo -- ECM solution
 * Copyright (c) 2014, WebTales (http://www.webtales.fr/).
 * All rights reserved.
 * licensing@webtales.fr
 *
 * Open Source License
 * ------------------------------------------------------------------------------------------
 * Rubedo is licensed under the terms of the Open Source GPL 3.0 license.
 *
 * @category   Rubedo
 * @package    Rubedo
 * @copyright  Copyright (c) 2012-2014 WebTales (http://www.webtales.fr)
 * @license    http://www.gnu.org/licenses/gpl.html Open Source GPL 3.0 license
 */
namespace Rubedo\Backoffice\Controller;

use Rubedo\Services\Manager;
use Zend\Json\Json;
use Zend\View\Model\JsonModel;
use Mongo\DataAccess;

/**
 * Controller providing data restore from data dump zipfile
 *
 * @author dfanchon
 * @category Rubedo
 * @package Rubedo
 *
 */
class RestoreController extends DataAccessController
{

    /**
     * Temporary file path
     */
    protected $_zipFileName = '/home/mickael/dump/dumpMG01-07-15.zip';
    
    /**
     * Default restore mode : INSERT or UPSERT
     */
    protected $_restoreMode = "insert";    
       
    public function indexAction()
    {
    	$contentService = Manager::getService('MongoDataAccess');
    	$fileService = Manager::getService('MongoFileAccess');
    	$fileService->init();

        $request = $this->getRequest();

        if($request->isPost()) {
            $mimeTypes = finfo_open(FILEINFO_MIME_TYPE);
            $zipObj = new \ZipArchive();

            $fileUploadInfo = $request->getFiles("zipFile");
            $tmpFullPath = $fileUploadInfo["tmp_name"];
            $originalZipFileName = $fileUploadInfo["name"];
            $fileMimeType = $fileUploadInfo["type"];

            if($zipObj->open($tmpFullPath)) {
                $randomTmpFolder = hash("sha1", $tmpFullPath.$originalZipFileName.mt_rand());
                $extractTmpFolder = sys_get_temp_dir()."/".$randomTmpFolder;

                if($zipObj->extractTo($extractTmpFolder)) {
                    $extractedFiles = scandir($extractTmpFolder);

                    foreach($extractedFiles as $file) {
                        if($file !== "." && $file !== "..") {
                            $filePath = $extractTmpFolder."/".$file;
                            $fileExtension = finfo_file($mimeTypes, $filePath);

                            switch ($fileExtension) {
                                case 'text/plain': // collection
                                    $fileContent = file_get_contents($filePath);
                                    $obj = json_decode($fileContent,TRUE);
                                    if (is_array($obj)) {
                                        $collectionName = array_keys($obj)[0];
                                        $contentService->init($collectionName);
                                        foreach ($obj[$collectionName]['data'] as $data) {
                                            echo $data['id'];
                                            if (\MongoId::isValid($data['id'])) {
                                                $data['_id'] = new \MongoId($data['id']);
                                                unset($data['id']);
                                                switch ($this->_restoreMode) {
                                                    case 'insert':
                                                        try {
                                                            $contentService->insert($data);
                                                        } catch (\Exception $e) {
                                                            continue;
                                                        }
                                                        break;
                                                    case 'upsert':
                                                        try {
                                                            $contentService->insert($data, ['upsert'=>TRUE]);
                                                        } catch (\Exception $e) {
                                                            continue;
                                                        }
                                                        break;
                                                }
                                            }
                                        }
                                    }
                                    break;
                                default: // file
                                    $buf = file_get_contents($filePath);
                                    $originalFileId = substr($file,0,24);
                                    $originalFileName = substr($file,25,strlen($file)-25);
                                    $obj = [
                                        '_id' => new \MongoId($originalFileId),
                                        'filename' => $originalFileName,
                                        'Content-Type' => $fileExtension,
                                        'bytes' => $buf
                                    ];
                                    $fileService->createBinary($obj);
                                    break;
                            }
                        }
                    }
                }

                $zipObj->close();
            }
        }
   	} 
}