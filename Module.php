<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\GoogleDrive;

use GuzzleHttp\Psr7\Request;

/**
 * Adds ability to work with Google Drive file storage inside Aurora Files module.
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @property Settings $oModuleSettings
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    protected static $sStorageType = 'google';
    protected static $iStorageOrder = 200;

    protected $oClient = null;

    protected $oService = null;

    protected $aRequireModules = array(
        'Files',
        'OAuthIntegratorWebclient',
        'GoogleAuthWebclient'
    );

    /**
     * @return Module
     */
    public static function getInstance()
    {
        return parent::getInstance();
    }

    /**
     * @return Module
     */
    public static function Decorator()
    {
        return parent::Decorator();
    }

    /**
     * @return Settings
     */
    public function getModuleSettings()
    {
        return $this->oModuleSettings;
    }

    protected function issetScope($sScope)
    {
        return \in_array($sScope, \explode(' ', $this->oModuleSettings->Scopes));
    }

    public function init()
    {
        $this->AddEntries(
            [
                'google-drive-thumb' => 'EntryThumbnail'
            ]
        );

        $this->subscribeEvent('GoogleAuthWebclient::PopulateScopes', array($this, 'onPopulateScopes'));
        $this->subscribeEvent('Files::GetStorages::after', array($this, 'onAfterGetStorages'));
        $this->subscribeEvent('Files::GetFile', array($this, 'onGetFile'));
        $this->subscribeEvent('Files::GetItems', array($this, 'onGetItems'));
        $this->subscribeEvent('Files::GetFileInfo::after', array($this, 'onAfterGetFileInfo'));
        $this->subscribeEvent('Files::GetFile::after', array($this, 'onAfterGetFile'));
        $this->subscribeEvent('Files::CreateFolder::after', array($this, 'onAfterCreateFolder'));
        $this->subscribeEvent('Files::CreateFile', array($this, 'onCreateFile'));
        $this->subscribeEvent('Files::CreatePublicLink::after', array($this, 'onAfterCreatePublicLink'));
        $this->subscribeEvent('Files::DeletePublicLink::after', array($this, 'onAfterDeletePublicLink'));
        $this->subscribeEvent('Files::Delete::after', array($this, 'onAfterDelete'));
        $this->subscribeEvent('Files::Rename::after', array($this, 'onAfterRename'));
        $this->subscribeEvent('Files::Move::after', array($this, 'onAfterMove'));
        $this->subscribeEvent('Files::Copy::after', array($this, 'onAfterCopy'));
        $this->subscribeEvent('Files::CheckUrl', array($this, 'onAfterCheckUrl'));
        $this->subscribeEvent('Files::PopulateFileItem::after', array($this, 'onAfterPopulateFileItem'));

        $this->subscribeEvent('Google::GetSettings', array($this, 'onGetSettings'));
        $this->subscribeEvent('Google::UpdateSettings::after', array($this, 'onAfterUpdateSettings'));

        $this->subscribeEvent('Files::GetItems::before', array($this, 'onCheckUrlFile'));
        $this->subscribeEvent('Files::UploadFile::before', array($this, 'onCheckUrlFile'));
        $this->subscribeEvent('Files::CreateFolder::before', array($this, 'onCheckUrlFile'));

        $this->subscribeEvent('Files::CheckQuota::after', array($this, 'onAfterCheckQuota'));
        $this->subscribeEvent('Files::GetQuota::after', array($this, 'onAfterGetQuota'));
    }

    public function onPopulateScopes($sScope, &$aResult)
    {
        $aScopes = \explode('|', $sScope);
        foreach ($aScopes as $sScope) {
            if ($sScope === 'storage') {
                $aResult[] = 'https://www.googleapis.com/auth/drive';
            }
        }
    }

    public function onAfterGetStorages($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

        if ($this->CheckDriveAccess()) {
            $mResult[] = [
                'Type' => self::$sStorageType,
                'IsExternal' => true,
                'DisplayName' => 'Google Drive',
                'Order' => self::$iStorageOrder,
            ];
        }
    }

    protected function GetClient()
    {
        if (!isset($this->oClient)) {
            if (class_exists('Aurora\Modules\Google\Module')) {
                $oGoogleModule = \Aurora\Modules\Google\Module::getInstance();
                if (!$oGoogleModule->oModuleSettings->EnableModule || !$this->issetScope('storage')) {
                    return false;
                }
            } else {
                return false;
            }

            $oOAuthIntegratorWebclientModule = \Aurora\Modules\OAuthIntegratorWebclient\Module::Decorator();
            $oSocialAccount = $oOAuthIntegratorWebclientModule->GetAccount(self::$sStorageType);
            if ($oSocialAccount) {
                $oGoogleModule = \Aurora\Modules\Google\Module::getInstance();
                if ($oGoogleModule) {
                    $oClient = new \Google\Client();
                    $oClient->setClientId($oGoogleModule->oModuleSettings->Id);
                    $oClient->setClientSecret($oGoogleModule->oModuleSettings->Secret);
                    $oClient->addScope([
                        'https://www.googleapis.com/auth/userinfo.email',
                        'https://www.googleapis.com/auth/userinfo.profile',
                        'https://www.googleapis.com/auth/drive'
                    ]);
                    $bRefreshToken = false;
                    try {
                        $oClient->setAccessToken($oSocialAccount->AccessToken);
                    } catch (\Exception $oException) {
                        $bRefreshToken = true;
                    }
                    if ($oClient->isAccessTokenExpired() || $bRefreshToken) {
                        $oClient->refreshToken($oSocialAccount->RefreshToken);
                        $oSocialAccount->AccessToken = $oClient->getAccessToken();
                        $oOAuthIntegratorWebclientModule->UpdateAccount($oSocialAccount);
                    }
                    if ($oClient->getAccessToken()) {
                        $this->oClient = $oClient;
                    }
                }
            }
        }

        return $this->oClient;
    }

    protected function GetDriveService()
    {
        if (!$this->CheckDriveAccess()) {
            return false;
        }

        if (!isset($this->oService)) {
            $oClient = $this->GetClient();
            if ($oClient) {
                $this->oService = new \Google\Service\Drive($oClient);
            }
        }

        return $this->oService;
    }

    protected function _dirname($sPath)
    {
        $sPath = \dirname($sPath);
        return \str_replace(DIRECTORY_SEPARATOR, '/', $sPath);
    }

    protected function _basename($sPath)
    {
        $aPath = \explode('/', $sPath);
        return \end($aPath);
    }

    /**
     * @param string $sType
     * @param string $sPath
     * @param \Google\Service\Drive\DriveFile $oFile
     */
    protected function PopulateFileInfo($sType, $sPath, $oFile)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

        $mResult = false;
        if ($oFile) {
            if (isset($oFile->shortcutDetails)) {
                $oFile->mimeType = $oFile->shortcutDetails['targetMimeType'];
                $oFile->id = $oFile->shortcutDetails['targetId'];
            }

            $this->PopulateGoogleDriveFileInfo($oFile);

            $mResult /*@var $mResult \Aurora\Modules\Files\Classes\FileItem */ = new  \Aurora\Modules\Files\Classes\FileItem();
            $mResult->IsExternal = true;
            $mResult->TypeStr = $sType;
            $mResult->IsFolder = ($oFile->mimeType === "application/vnd.google-apps.folder");
            $mResult->Id = $oFile->id;
            $mResult->Name = $oFile->name;
            $mResult->Path = '';
            $mResult->Size = $oFile->size;
            $mResult->FullPath = $oFile->id;
            $mResult->ContentType = $oFile->mimeType;
            if (isset($oFile->thumbnailLink)) {
                $mResult->Thumb = true;
                $mResult->ThumbnailUrl = '?google-drive-thumb/' . \Aurora\System\Utils::UrlSafeBase64Encode($oFile->thumbnailLink);
            }

            if ($mResult->IsFolder) {
                $mResult->AddAction([
                    'list' => []
                ]);
            } else {
                $mResult->AddAction([
                    'view' => [
                        'url' => '?download-file/' . $this->getItemHash($mResult) . '/view'
                    ]
                ]);
                $mResult->AddAction([
                    'download' => [
                        'url' => '?download-file/' . $this->getItemHash($mResult)
                    ]
                ]);
            }

            //				$oItem->Owner = $oSocial->Name;
            $mResult->LastModified = \date_timestamp_get(date_create($oFile->createdTime));
        }

        return $mResult;
    }

    protected function _getFileInfo($sName)
    {
        $mResult = false;
        $oService = $this->GetDriveService();
        if ($oService) {
            $mResult = $oService->files->get($sName);
        }

        return $mResult;
    }

    /**
     *
     * @param \Aurora\Modules\Files\Classes\FileItem $oItem
     * @return string
     */
    protected function getItemHash($oItem)
    {
        return \Aurora\System\Api::EncodeKeyValues(array(
            'UserId' => \Aurora\System\Api::getAuthenticatedUserId(),
            'Type' => $oItem->TypeStr,
            'Path' => '',
            'Name' => $oItem->FullPath,
            'FileName' => $oItem->Name
        ));
    }

    /**
     * @param array $aArgs
     * @param mixed $mResult
     */
    public function onAfterGetFileInfo($aArgs, &$mResult)
    {
        if ($aArgs['Type'] === self::$sStorageType) {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);
            $oFileInfo = $this->_getFileInfo($aArgs['Id']);
            if ($oFileInfo) {
                $mResult = $this->PopulateFileInfo($aArgs['Type'], $aArgs['Path'], $oFileInfo);
                return true;
            }
        }
    }

    /**
     */
    public function onGetFile($aArgs, &$Result)
    {
        if ($aArgs['Type'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $oFile = $oService->files->get($aArgs['Id']);

                $sMimeType = $this->getMimeTypeForExport($oFile);
                $aArgs['Name'] = $oFile->name;

                if (empty($sMimeType)) {
                    $sFileData = $oService->files->get($aArgs['Id'], ['alt' => 'media']);
                } else {
                    $sFileData = $oService->files->export($aArgs['Id'], $sMimeType, [
                        'alt' => 'media'
                    ]);
                }

                $Result = \fopen('php://memory', 'r+');
                \fwrite($Result, $sFileData->getBody());
                \rewind($Result);

                return true;
            }
        }
    }

    public function onCheckUrlFile(&$aArgs, &$mResult)
    {
        if ($this->CheckDriveAccess() && (\pathinfo($aArgs['Path'], PATHINFO_EXTENSION) === 'url' || strpos($aArgs['Path'], '.url/'))) {
            list($sUrl, $sId) = explode('.url', $aArgs['Path']);
            $sUrl .= '.url';
            $aArgs['Path'] = $sUrl;
            $this->prepareArgs($aArgs);
            if ($sId && $aArgs['Type'] === self::$sStorageType) {
                $aArgs['Path'] = basename($sId);
            } elseif ($aArgs['Type'] !== self::$sStorageType) {
                $aArgs['Path'] = $sUrl . $sId;
            }
        }
    }

    /**
     * @param array $aArgs
     * @param mixed $mResult
     */
    public function onGetItems($aArgs, &$mResult)
    {
        if ($aArgs['Type'] === self::$sStorageType) {
            $mResult = [];
            $oService = $this->GetDriveService();
            if ($oService) {
                $sPath = \ltrim(\basename($aArgs['Path']), '/');

                $aFileItems = [];
                $sPageToken = null;

                if (empty($sPath)) {
                    $sPath = 'root';
                }

                $sQuery  = "'" . $sPath . "' in parents and trashed = false";
                if (!empty($aArgs['Pattern'])) {
                    $sQuery .= " and name contains '" . $aArgs['Pattern'] . "'";
                }

                do {
                    try {
                        $aParameters = [
                            'q' => $sQuery,
                            'fields' => '*',
                            'orderBy' => 'name'
                        ];
                        if ($sPageToken) {
                            $aParameters['pageToken'] = $sPageToken;
                        }

                        $oFiles = $oService->files->listFiles($aParameters);
                        $aFileItems = \array_merge($aFileItems, $oFiles->getFiles());
                        $sPageToken = $oFiles->getNextPageToken();
                    } catch (\Exception $e) {
                        $sPageToken = null;
                    }
                } while ($sPageToken);

                foreach ($aFileItems as $oChild) {
                    $oItem /*@var $oItem \Aurora\Modules\Files\Classes\FileItem */ = $this->PopulateFileInfo($aArgs['Type'], $aArgs['Path'], $oChild);
                    if ($oItem) {
                        $mResult[] = $oItem;
                    }
                }

                if (isset($aArgs['PathRequired']) && $aArgs['PathRequired'] === true) {
                    $mResult['Path'] = array();
                    if ($sPath !== 'root') {
                        $oPathInfo = $oService->files->get($sPath);
                        $mResult['Path'][] = $this->PopulateFileInfo($aArgs['Type'], $aArgs['Path'], $oPathInfo);
                        while (true) {
                            $aParrents = $oService->parents->listParents($sPath);
                            if ($aParrents == null || count($aParrents) == 0) {
                                break;
                            }
                            $oParrent = $aParrents[0];
                            $sPath = $oParrent->id;
                            if (!$oParrent->isRoot) {
                                $oItem = $oService->files->get($sPath);
                                if ($oItem) {
                                    $mResult['Path'][] = $this->PopulateFileInfo($aArgs['Type'], $aArgs['Path'], $oItem);
                                }
                            }
                        }
                    }
                }
            }

            return true;
        }
    }

    protected function prepareArgs(&$aData)
    {
        $aPathInfo = \pathinfo($aData['Path']);
        $sExtension = isset($aPathInfo['extension']) ? $aPathInfo['extension'] : '';
        if ($sExtension === 'url') {
            $aArgs = array(
                'UserId' => $aData['UserId'],
                'Type' => $aData['Type'],
                'Path' => $aPathInfo['dirname'],
                'Name' => $aPathInfo['basename'],
                'Id' => $aPathInfo['basename'],
                'IsThumb' => false
            );
            $mResult = false;
            \Aurora\System\Api::GetModuleManager()->broadcastEvent(
                'Files',
                'GetFile',
                $aArgs,
                $mResult
            );
            if (\is_resource($mResult)) {
                $aUrlFileInfo = \Aurora\System\Utils::parseIniString(\stream_get_contents($mResult));
                if ($aUrlFileInfo && isset($aUrlFileInfo['URL'])) {
                    if ((false !== \strpos($aUrlFileInfo['URL'], 'drive.google.com'))) {
                        $aData['Type'] = 'google';
                        $aData['Path'] = $this->GetIdByLink($aUrlFileInfo['URL']);
                    }
                }
            }
        }
    }

    /**
     * @param array $aArgs
     * @param mixed $mResult
     */
    public function onAfterCreateFolder(&$aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['Type'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $folder = new \Google\Service\Drive\DriveFile();
                $folder->setName($aArgs['FolderName']);
                $folder->setMimeType('application/vnd.google-apps.folder');

                // Set the parent folder.
                if ($aArgs['Path'] != null) {
                    $folder->setParents(array($aArgs['Path']));
                }

                try {
                    $oService->files->create($folder, array());
                    $mResult = true;
                } catch (\Exception $ex) {
                    $mResult = false;
                }
            }
        }
    }

    /**
     * @param array $aArgs
     * @param mixed $mResult
     */
    public function onCreateFile($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['Type'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $sMimeType = \MailSo\Base\Utils::MimeContentType($aArgs['Name']);
                $file = new \Google\Service\Drive\DriveFile();
                $file->setName($aArgs['Name']);
                $file->setMimeType($sMimeType);

                $Path = \trim($aArgs['Path'], '/');
                // Set the parent folder.
                if ($Path != null) {
                    $file->setParents(array($Path));
                }

                try {
                    $sData = '';
                    if (\is_resource($aArgs['Data'])) {
                        \rewind($aArgs['Data']);
                        $sData = \stream_get_contents($aArgs['Data']);
                    } else {
                        $sData = $aArgs['Data'];
                    }
                    $oService->files->create($file, array(
                        'data' => $sData,
                        'mimeType' => $sMimeType,
                        'uploadType' => 'media'
                    ));
                    $mResult = true;
                } catch (\Exception $ex) {
                    $mResult = false;
                }
            }
        }
    }

    /**
     * @param array $aArgs
     * @param mixed $mResult
     */
    public function onAfterDelete(&$aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['Type'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $mResult = false;

                foreach ($aArgs['Items'] as $aItem) {
                    try {
                        $oService->files->delete($aItem['Name']);
                        $mResult = true;
                    } catch (\Exception $ex) {
                        $mResult = false;
                    }
                }
            }
        }
    }

    /**
     * @param array $aArgs
     * @param mixed $mResult
     */
    public function onAfterRename(&$aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['Type'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $mResult = false;

                $file = new \Google\Service\Drive\DriveFile();
                $file->setName($aArgs['NewName']);

                $additionalParams = array();

                try {
                    $oService->files->update($aArgs['Name'], $file, $additionalParams);
                    $mResult = true;
                } catch (\Exception $ex) {
                    $mResult = false;
                }
            }
        }
    }

    /**
     * @param array $aArgs
     * @param mixed $mResult
     */
    public function onAfterMove(&$aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['FromType'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $mResult = false;

                $aArgs['FromPath'] = $aArgs['FromPath'] === '' ? 'root' : \trim($aArgs['FromPath'], '/');
                $aArgs['ToPath'] = $aArgs['ToPath'] === '' ? 'root' : \trim($aArgs['ToPath'], '/');

                foreach ($aArgs['Files'] as $aItem) {
                    $oFile = $oService->files->get($aItem['Name'], ['fields' => 'parents']);
                    try {
                        $previousParents = join(',', $oFile->parents);
                        $emptyFileMetadata = new \Google\Service\Drive\DriveFile();
                        $oFile = $oService->files->update(
                            $aItem['Name'],
                            $emptyFileMetadata,
                            [
                          'addParents' => $aArgs['ToPath'],
                          'removeParents' => $previousParents,
                          'fields' => 'id, parents']
                        );

                        $mResult = true;
                    } catch (\Exception $ex) {
                        $mResult = false;
                    }
                }
            }
        }
    }

    /**
     * @param array $aArgs
     * @param mixed $mResult
     */
    public function onAfterCopy(&$aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['FromType'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $mResult = false;

                $aArgs['ToPath'] = $aArgs['ToPath'] === '' ? 'root' : \trim($aArgs['ToPath'], '/');

                foreach ($aArgs['Files'] as $aItem) {
                    try {
                        $emptyFileMetadata = new \Google\Service\Drive\DriveFile();
                        $emptyFileMetadata->parents = [$aArgs['ToPath']];
                        $oService->files->copy(
                            $aItem['Name'],
                            $emptyFileMetadata,
                            [
                          'fields' => 'id, parents']
                        );

                        $mResult = true;
                    } catch (\Exception $ex) {
                        $mResult = false;
                    }
                }
            }
        }
    }

    /**
     * @param array $aArgs
     * @param mixed $oItem
     */
    public function onAfterPopulateFileItem($aArgs, &$oItem)
    {
        if ($oItem->IsLink) {
            if (false !== strpos($oItem->LinkUrl, 'drive.google.com')) {
                $oItem->LinkType = 'google';

                $oFileInfo = $this->GetLinkInfo($oItem->LinkUrl);
                if ($oFileInfo) {
                    if (isset($oFileInfo->thumbnailLink)) {
                        $oItem->Thumb = true;
                        $oItem->ThumbnailUrl = $oFileInfo->thumbnailLink;
                    }
                    if ($oFileInfo->mimeType === "application/vnd.google-apps.folder") {
                        $oItem->UnshiftAction(array(
                            'list' => array()
                        ));

                        $oItem->Thumb = true;
                        $oItem->ThumbnailUrl = \MailSo\Base\Http::SingletonInstance()->GetFullUrl() . 'modules/' . self::GetName() . '/images/drive.png';
                    } else {
                        $oItem->Size = isset($oFileInfo->fileSize) ? $oFileInfo->fileSize : $oItem->Size;
                    }
                }
                return true;
            }
        }
    }

    protected function getMimeTypeForExport(&$oFileInfo)
    {
        switch($oFileInfo->mimeType) {
            case 'application/vnd.google-apps.document':
                $oFileInfo->name = $oFileInfo->name . '.docx';
                return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                break;
            case 'application/vnd.google-apps.spreadsheet':
                $oFileInfo->name = $oFileInfo->name . '.xlsx';
                return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                break;
            case 'application/vnd.google-apps.drawing':
                $oFileInfo->name = $oFileInfo->name . '.png';
                return 'image/png';
                break;
            case 'application/vnd.google-apps.presentation':
                $oFileInfo->name = $oFileInfo->name . '.pptx';
                return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                break;
            default:
                return '';
                break;
        }
    }

    protected function PopulateGoogleDriveFileInfo(&$oFileInfo)
    {
        if ($oFileInfo->mimeType !== "application/vnd.google-apps.folder") {
            $this->getMimeTypeForExport($oFileInfo);
        }
    }

    protected function GetIdByLink($sLink)
    {
        $matches = array();
        \preg_match("%https://\w+\.google\.com/\w+/\w+/(.*)\?.*%", $sLink, $matches);
        if (!isset($matches[1])) {
            \preg_match("%https://\w+\.google\.com/open\?id=(.*)%", $sLink, $matches);
        }

        return isset($matches[1]) ? $matches[1] : '';
    }

    protected function GetLinkInfo($sLink, $bLinkAsId = false)
    {
        $mResult = false;
        $sGDId = '';
        if ($bLinkAsId) {
            $sGDId = $sLink;
        } else {
            $sGDId = $this->GetIdByLink($sLink);
        }

        if ($sGDId !== '') {
            $oFileInfo = $this->_getFileInfo($sGDId);
            if ($oFileInfo) {
                $this->PopulateGoogleDriveFileInfo($oFileInfo);
                $mResult = $oFileInfo;
            } else {
                $mResult = false;
            }
        } else {
            $mResult = false;
        }

        return $mResult;
    }

    /**
     * Passes data to connect to service.
     *
     * @ignore
     * @param string $aArgs Service type to verify if data should be passed.
     * @param boolean|array $mResult variable passed by reference to take the result.
     */
    public function onGetSettings($aArgs, &$mResult)
    {
        $oUser = \Aurora\System\Api::getAuthenticatedUser();

        if ($oUser) {
            $aScope = array(
                'Name' => 'storage',
                'Description' => $this->i18N('SCOPE_FILESTORAGE'),
                'Value' => false
            );
            if ($oUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin) {
                $aScope['Value'] = $this->issetScope('storage');
                $mResult['Scopes'][] = $aScope;
            }
            if ($oUser->isNormalOrTenant()) {
                if ($aArgs['OAuthAccount'] instanceof \Aurora\Modules\OAuthIntegratorWebclient\Models\OauthAccount) {
                    $aScope['Value'] = $aArgs['OAuthAccount']->issetScope('storage');
                }
                if ($this->issetScope('storage')) {
                    $mResult['Scopes'][] = $aScope;
                }
            }
        }
    }

    public function onAfterUpdateSettings($aArgs, &$mResult)
    {
        $sScope = '';
        if (isset($aArgs['Scopes']) && is_array($aArgs['Scopes'])) {
            foreach ($aArgs['Scopes'] as $aScope) {
                if ($aScope['Name'] === 'storage') {
                    if ($aScope['Value']) {
                        $sScope = 'storage';
                        break;
                    }
                }
            }
        }
        $this->setConfig('Scopes', $sScope);
        $this->saveModuleConfig();
    }

    public function onAfterCheckUrl(&$aArgs, &$aReslult) {}

    public function onAfterGetQuota(&$aArgs, &$aResult)
    {
        if ($aArgs['Type'] === self::$sStorageType) {
            $mResult = [0, 0];
            return true;
        }
    }

    public function onAfterCheckQuota(&$aArgs, &$mResult)
    {
        if ($aArgs['Type'] === self::$sStorageType) {
            $mResult = true;
            return true;
        }
    }

    public function EntryThumbnail()
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        $sUrl =  \Aurora\System\Utils::UrlSafeBase64Decode(\Aurora\System\Router::getItemByIndex(1, ''));

        $request = new Request(
            'GET',
            $sUrl
        );

        $client = $this->GetClient();
        $response = $client->execute($request);
        echo $response->getBody();
    }

    protected function CheckDriveAccess()
    {
        $bEnableGoogleModule = false;

        if (class_exists('Aurora\Modules\Google\Module')) {
            $oGoogleModule = \Aurora\Modules\Google\Module::getInstance();
            $bEnableGoogleModule = $oGoogleModule->oModuleSettings->EnableModule;
        }

        $oOAuthAccount = \Aurora\Modules\OAuthIntegratorWebclient\Module::Decorator()->GetAccount(self::$sStorageType);

        return ($oOAuthAccount instanceof \Aurora\Modules\OAuthIntegratorWebclient\Models\OauthAccount
            && $oOAuthAccount->Type === self::$sStorageType
            && $bEnableGoogleModule
            && $this->issetScope('storage')
            && $oOAuthAccount->issetScope('storage'));
    }
}
