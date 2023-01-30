<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\GoogleDrive;

/**
 * Adds ability to work with Google Drive file storage inside Aurora Files module.
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
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

    protected function issetScope($sScope)
    {
        return \in_array($sScope, \explode(' ', $this->getConfig('Scopes')));
    }

    public function init()
    {
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

        $this->subscribeEvent('Files::GetItems::before', array($this, 'CheckUrlFile'));
        $this->subscribeEvent('Files::UploadFile::before', array($this, 'CheckUrlFile'));
        $this->subscribeEvent('Files::CreateFolder::before', array($this, 'CheckUrlFile'));

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

        $bEnableGoogleModule = false;
        $oGoogleModule = \Aurora\System\Api::GetModule('Google');
        if ($oGoogleModule instanceof \Aurora\System\Module\AbstractModule) {
            $bEnableGoogleModule = $oGoogleModule->getConfig('EnableModule', false);
        } else {
            $bEnableGoogleModule = false;
        }

        $oOAuthAccount = \Aurora\Modules\OAuthIntegratorWebclient\Module::Decorator()->GetAccount(self::$sStorageType);

        if ($oOAuthAccount instanceof \Aurora\Modules\OAuthIntegratorWebclient\Models\OauthAccount &&
                $oOAuthAccount->Type === self::$sStorageType && $bEnableGoogleModule &&
                    $this->issetScope('storage') && $oOAuthAccount->issetScope('storage')) {
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
            $oGoogleModule = \Aurora\System\Api::GetModule('Google');
            if ($oGoogleModule instanceof \Aurora\System\Module\AbstractModule) {
                if (!$oGoogleModule->getConfig('EnableModule', false) || !$this->issetScope('storage')) {
                    return false;
                }
            } else {
                return false;
            }

            $oOAuthIntegratorWebclientModule = \Aurora\Modules\OAuthIntegratorWebclient\Module::Decorator();
            $oSocialAccount = $oOAuthIntegratorWebclientModule->GetAccount(self::$sStorageType);
            if ($oSocialAccount) {
                $oGoogleModule = \Aurora\Modules\Google\Module::Decorator();
                if ($oGoogleModule) {
                    $oClient = new \Google_Client();
                    $oClient->setClientId($oGoogleModule->getConfig('Id', ''));
                    $oClient->setClientSecret($oGoogleModule->getConfig('Secret', ''));
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
        if (!isset($this->oService)) {
            $oClient = $this->GetClient();
            if ($oClient) {
                $this->oService = new \Google_Service_Drive($oClient);
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
     * @param array $aData
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
            $mResult->Size = $oFile->fileSize;
            $mResult->FullPath = $oFile->id;
            $mResult->ContentType = $oFile->mimeType;
            if (isset($oFile->thumbnailUrl)) {
                $mResult->Thumb = true;
                $mResult->ThumbnailUrl = $oFile->thumbnailUrl;
            }

            if ($mResult->IsFolder) {
                $mResult->AddAction([
                    'list' => []
                ]);
            } else {
                $mResult->AddAction([
                    'view' => [
                        'url' => '?download-file/' . $this->getItemHash($mResult) .'/view'
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
     * @param type $oItem
     * @return type
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
     * @param \Aurora\Modules\StandardAuth\Classes\Account $oAccount
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

    public function CheckUrlFile(&$aArgs, &$mResult)
    {
        if (strpos($aArgs['Path'], '.url') !== false) {
            list($sUrl, $sId) = explode('.url', $aArgs['Path']);
            $sUrl .= '.url';
            $aArgs['Path'] = $sUrl;
            $this->prepareArgs($aArgs);
            if ($sId) {
                $aArgs['Path'] = basename($sId);
            }
        }
    }

    /**
     * @param \Aurora\Modules\StandardAuth\Classes\Account $oAccount
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

                $sQuery  = "'".$sPath."' in parents and trashed = false";
                if (!empty($aArgs['Pattern'])) {
                    $sQuery .= " and name contains '".$aArgs['Pattern']."'";
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
                            if ($aParrents == null ||count($aParrents) == 0) {
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
     * @param \Aurora\Modules\StandardAuth\Classes\Account $oAccount
     */
    public function onAfterCreateFolder(&$aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['Type'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $folder = new \Google_Service_Drive_DriveFile();
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
     * @param \Aurora\Modules\StandardAuth\Classes\Account $oAccount
     */
    public function onCreateFile($aArgs, &$Result)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['Type'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $sMimeType = \MailSo\Base\Utils::MimeContentType($aArgs['Name']);
                $file = new \Google_Service_Drive_DriveFile();
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
                    $Result = true;
                } catch (\Exception $ex) {
                    $Result = false;
                }
            }
        }
    }

    /**
     * @param \Aurora\Modules\StandardAuth\Classes\Account $oAccount
     */
    public function onAfterDelete(&$aData, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aData['Type'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $mResult = false;

                foreach ($aData['Items'] as $aItem) {
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
     * @param \Aurora\Modules\StandardAuth\Classes\Account $oAccount
     */
    public function onAfterRename(&$aData, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aData['Type'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $mResult = false;

                $file = new \Google_Service_Drive_DriveFile();
                $file->setName($aData['NewName']);

                $additionalParams = array();

                try {
                    $oService->files->update($aData['Name'], $file, $additionalParams);
                    $mResult = true;
                } catch (\Exception $ex) {
                    $mResult = false;
                }
            }
        }
    }

    /**
     * @param \Aurora\Modules\StandardAuth\Classes\Account $oAccount
     */
    public function onAfterMove(&$aData, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aData['FromType'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $mResult = false;

                $aData['FromPath'] = $aData['FromPath'] === '' ? 'root' : \trim($aData['FromPath'], '/');
                $aData['ToPath'] = $aData['ToPath'] === '' ? 'root' : \trim($aData['ToPath'], '/');

                foreach ($aData['Files'] as $aItem) {
                    $oFile = $oService->files->get($aItem['Name'], ['fields' => 'parents']);
                    try {
                        $previousParents = join(',', $oFile->parents);
                        $emptyFileMetadata = new \Google_Service_Drive_DriveFile();
                        $oFile = $oService->files->update(
                            $aItem['Name'],
                            $emptyFileMetadata,
                            [
                          'addParents' => $aData['ToPath'],
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
     * @param \Aurora\Modules\StandardAuth\Classes\Account $oAccount
     */
    public function onAfterCopy(&$aData, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aData['FromType'] === self::$sStorageType) {
            $oService = $this->GetDriveService();
            if ($oService) {
                $mResult = false;

                $aData['ToPath'] = $aData['ToPath'] === '' ? 'root' : \trim($aData['ToPath'], '/');

                foreach ($aData['Files'] as $aItem) {
                    try {
                        $emptyFileMetadata = new \Google_Service_Drive_DriveFile();
                        $emptyFileMetadata->parents = [$aData['ToPath']];
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
        \preg_match("%https://\w+\.google\.com/\w+/d/(.*?)/.*%", $sLink, $matches);
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

        if (!empty($oUser)) {
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

    public function onAfterCheckUrl(&$aArgs, &$aReslult)
    {
    }

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
}
