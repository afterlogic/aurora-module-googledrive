<?php
/**
 * @copyright Copyright (c) 2016, Afterlogic Corp.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 * 
 * @package Modules
 */

class GoogleDriveModule extends AApiModule
{
	protected static $sService = 'google';
	
	protected $aRequireModules = array(
		'OAuthIntegratorWebclient', 
		'GoogleAuthWebclient'
	);
	
	public function init() 
	{
		$this->subscribeEvent('Files::GetStorages::after', array($this, 'onAfterGetStorages'));
		$this->subscribeEvent('Files::GetFile', array($this, 'onGetFile'));
		$this->subscribeEvent('Files::GetFiles::after', array($this, 'onAfterGetFiles'));
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
		$this->subscribeEvent('Files::PopulateFileItem', array($this, 'onPopulateFileItem'));
		$this->subscribeEvent('Google::GetSettings', array($this, 'onGetSettings'));
		
		$this->subscribeEvent('Files::GetFiles::before', array($this, 'CheckUrlFile'));
		$this->subscribeEvent('Files::UploadFile::before', array($this, 'CheckUrlFile'));
		$this->subscribeEvent('Files::CreateFolder::before', array($this, 'CheckUrlFile'));
	}
	
	public function onAfterGetStorages($aArgs, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		$oOAuthAccount = \CApi::GetModuleDecorator('OAuthIntegratorWebclient')->GetAccount(self::$sService);

		if ($oOAuthAccount instanceof COAuthAccount && 
				$oOAuthAccount->Type === self::$sService && 
					$oOAuthAccount->issetScope('filestorage'))
		{		
			$mResult[] = [
				'Type' => self::$sService, 
				'IsExternal' => true,
				'DisplayName' => 'Google Drive'
			];
		}
	}
	
	protected function GetClient()
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		$mResult = false;
		$oOAuthIntegratorWebclientModule = \CApi::GetModuleDecorator('OAuthIntegratorWebclient');
		$oSocialAccount = $oOAuthIntegratorWebclientModule->GetAccount(self::$sService);
		if ($oSocialAccount)
		{
			$oGoogleModule = \CApi::GetModuleDecorator('Google');
			if ($oGoogleModule)
			{
				$oClient = new Google_Client();
				$oClient->setClientId($oGoogleModule->getConfig('Id', ''));
				$oClient->setClientSecret($oGoogleModule->getConfig('Secret', ''));
				$oClient->addScope('https://www.googleapis.com/auth/userinfo.email');
				$oClient->addScope('https://www.googleapis.com/auth/userinfo.profile');
				$oClient->addScope("https://www.googleapis.com/auth/drive");
				$bRefreshToken = false;
				try
				{
					$oClient->setAccessToken($oSocialAccount->AccessToken);
				}
				catch (Exception $oException)
				{
					$bRefreshToken = true;
				}
				if ($oClient->isAccessTokenExpired() || $bRefreshToken) 
				{
					$oClient->refreshToken($oSocialAccount->RefreshToken);
					$oSocialAccount->AccessToken = $oClient->getAccessToken();
					$oOAuthIntegratorWebclientModule->UpdateAccount($oSocialAccount);
				}				
				if ($oClient->getAccessToken())
				{
					$mResult = $oClient;
				}
			}
		}
		
		return $mResult;
	}	

	protected function _dirname($sPath)
	{
		$sPath = dirname($sPath);
		return str_replace(DIRECTORY_SEPARATOR, '/', $sPath); 
	}
	
	protected function _basename($sPath)
	{
		$aPath = explode('/', $sPath);
		return end($aPath); 
	}

	/**
	 * @param array $aData
	 */
	protected function PopulateFileInfo($sType, $sPath, $oFile)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		$mResult = false;
		if ($oFile)
		{
			$this->PopulateGoogleDriveFileInfo($oFile);
			$mResult /*@var $mResult \CFileStorageItem */ = new  \CFileStorageItem();
			$mResult->IsExternal = true;
			$mResult->TypeStr = $sType;
			$mResult->IsFolder = ($oFile->mimeType === "application/vnd.google-apps.folder");
			$mResult->Id = $oFile->id;
			$mResult->Name = $oFile->title;
			$mResult->Path = '';
			$mResult->Size = $oFile->fileSize;
			$mResult->FullPath = $oFile->id;
			if (isset($oFile->thumbnailLink))
			{
				$mResult->Thumb = true;
				$mResult->ThumbnailLink = $oFile->thumbnailLink;
			}
			

//				$oItem->Owner = $oSocial->Name;
			$mResult->LastModified = date_timestamp_get(date_create($oFile->createdDate));
		}

		return $mResult;
	}	
	
	protected function _getFileInfo($sName)
	{
		$mResult = false;
		$oClient = $this->GetClient();
		if ($oClient)
		{
			$oDrive = new Google_Service_Drive($oClient);
			$mResult = $oDrive->files->get($sName);
		}
		
		return $mResult;
	}
	
	/**
	 * @param \CAccount $oAccount
	 */
	public function onAfterGetFileInfo($aArgs)
	{
		$mResult = false;
		if ($aArgs['Type'] === self::$sService)
		{
			\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
			$oFileInfo = $this->_getFileInfo($aArgs['Name']);
			if ($oFileInfo)
			{
				$mResult = $this->PopulateFileInfo($aArgs['Type'], $aArgs['Path'], $oFileInfo);
			}
		}
		
		return $mResult;
	}	
	
	/**
	 */
	public function onGetFile($aArgs, &$Result)
	{
		if ($aArgs['Type'] === self::$sService)
		{
			$oClient = $this->GetClient();
			if ($oClient)
			{
				$oDrive = new Google_Service_Drive($oClient);
				$oFile = $oDrive->files->get($aArgs['Name']);
				
				$aArgs['Name'] = $oFile->originalFilename;

				$this->PopulateGoogleDriveFileInfo($oFile);
				$oRequest = new Google_Http_Request($oFile->downloadUrl, 'GET', null, null);
				$oClientAuth = $oClient->getAuth();
				$oClientAuth->sign($oRequest);
				$oHttpRequest = $oClientAuth->authenticatedRequest($oRequest);			
				if ($oHttpRequest->getResponseHttpCode() === 200) 
				{
					$Result = fopen('php://memory','r+');
					fwrite($Result, $oHttpRequest->getResponseBody());
					rewind($Result);
					
					return true;
				} 
			}
		}
	}	
	
	public function CheckUrlFile(&$aArgs, &$mResult)
	{
		if (strpos($aArgs['Path'], '.url') !== false)
		{
			list($sUrl, $sId) = explode('.url', $aArgs['Path']);
			$sUrl .= '.url';
			$aArgs['Path'] = $sUrl;
			$this->prepareArgs($aArgs);
			if ($sId)
			{
				$aArgs['Path'] = basename($sId);
			}
		}
	}

	/**
	 * @param \CAccount $oAccount
	 */
	public function onAfterGetFiles($aArgs, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		if ($aArgs['Type'] === self::$sService)
		{
			$mResult['Items'] = array();
			$oClient = $this->GetClient();
			if ($oClient)
			{
				$mResult['Items']  = array();
				$oDrive = new Google_Service_Drive($oClient);
				$sPath = ltrim(basename($aArgs['Path']), '/');

				$aFileItems = array();
				$sPageToken = NULL;			

				if (empty($sPath))
				{
					$sPath = 'root';
				}

				$sQuery  = "'".$sPath."' in parents and trashed = false";
				if (!empty($aArgs['Pattern']))
				{
					$sQuery .= " and title contains '".$aArgs['Pattern']."'";
				}

				do 
				{
					try 
					{
						$aParameters = array('q' => $sQuery);
						if ($sPageToken) 
						{
							$aParameters['pageToken'] = $sPageToken;
						}

						$oFiles = $oDrive->files->listFiles($aParameters);
						$aFileItems = array_merge($aFileItems, $oFiles->getItems());
						$sPageToken = $oFiles->getNextPageToken();
					} 
					catch (Exception $e) 
					{
						$sPageToken = NULL;
					}
				} 
				while ($sPageToken);			

				foreach($aFileItems as $oChild) 
				{
					$oItem /*@var $oItem \CFileStorageItem */ = $this->PopulateFileInfo($aArgs['Type'], $aArgs['Path'], $oChild);
					if ($oItem)
					{
						$mResult['Items'][] = $oItem;
					}
				}
			}
		}
	}	

	protected function prepareArgs(&$aData)
	{
		$aPathInfo = pathinfo($aData['Path']);
		$sExtension = isset($aPathInfo['extension']) ? $aPathInfo['extension'] : '';
		if ($sExtension === 'url')
		{
			$aArgs = array(
				'UserId' => $aData['UserId'],
				'Type' => $aData['Type'],
				'Path' => $aPathInfo['dirname'],
				'Name' => $aPathInfo['basename'],
				'IsThumb' => false
			);
			$mResult = false;
			\CApi::GetModuleManager()->broadcastEvent(
				'Files',
				'GetFile', 
				$aArgs,
				$mResult
			);	
			if (is_resource($mResult))
			{
				$aUrlFileInfo = \api_Utils::parseIniString(stream_get_contents($mResult));
				if ($aUrlFileInfo && isset($aUrlFileInfo['URL']))
				{
					if ((false !== strpos($aUrlFileInfo['URL'], 'drive.google.com')))
					{
						$aData['Type'] = 'google';
						$aData['Path'] = $this->GetIdByLink($aUrlFileInfo['URL']);
					}
				}
			}		
		}
	}
	
	/**
	 * @param \CAccount $oAccount
	 */
	public function onAfterCreateFolder(&$aArgs, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sService)
		{
			$oClient = $this->GetClient();
			if ($oClient)
			{
				$folder = new Google_Service_Drive_DriveFile();
				$folder->setTitle($aArgs['FolderName']);
				$folder->setMimeType('application/vnd.google-apps.folder');

				// Set the parent folder.
				if ($aArgs['Path'] != null) 
				{
				  $parent = new Google_Service_Drive_ParentReference();
				  $parent->setId($aArgs['Path']);
				  $folder->setParents(array($parent));
				}

				$oDrive = new Google_Service_Drive($oClient);
				try 
				{
					$oDrive->files->insert($folder, array());
					$mResult = true;
				} 
				catch (Exception $ex) 
				{
					$mResult = false;
				}				
			}
		}
	}	

	/**
	 * @param \CAccount $oAccount
	 */
	public function onCreateFile($aArgs, &$Result)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);

		if ($aArgs['Type'] === self::$sService)
		{
			$oClient = $this->GetClient();
			if ($oClient)
			{
				$sMimeType = \MailSo\Base\Utils::MimeContentType($aArgs['Name']);
				$file = new Google_Service_Drive_DriveFile();
				$file->setTitle($aArgs['Name']);
				$file->setMimeType($sMimeType);

				$Path = trim($aArgs['Path'], '/');
				// Set the parent folder.
				if ($Path != null) 
				{
				  $parent = new Google_Service_Drive_ParentReference();
				  $parent->setId($Path);
				  $file->setParents(array($parent));
				}

				$oDrive = new Google_Service_Drive($oClient);
				try 
				{
					$sData = '';
					if (is_resource($aArgs['Data']))
					{
						rewind($aArgs['Data']);
						$sData = stream_get_contents($aArgs['Data']);
					}
					else
					{
						$sData = $aArgs['Data'];
					}
					$oDrive->files->insert($file, array(
						'data' => $sData,
						'mimeType' => $sMimeType,
						'uploadType' => 'media'
					));
					$Result = true;
				} 
				catch (Exception $ex) 
				{
					$Result = false;
				}				
			}
		}
	}	

	/**
	 * @param \CAccount $oAccount
	 */
	public function onAfterDelete(&$aData, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aData['Type'] === self::$sService)
		{
			$oClient = $this->GetClient();
			if ($oClient)
			{
				$mResult = false;
				$oDrive = new Google_Service_Drive($oClient);

				foreach ($aData['Items'] as $aItem)
				{
					try 
					{
						$oDrive->files->trash($aItem['Name']);
						$mResult = true;
					} 
					catch (Exception $ex) 
					{
						$mResult = false;
					}
				}
			}
		}
	}	

	/**
	 * @param \CAccount $oAccount
	 */
	public function onAfterRename(&$aData, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aData['Type'] === self::$sService)
		{
			$oClient = $this->GetClient();
			if ($oClient)
			{
				$mResult = false;
				$oDrive = new Google_Service_Drive($oClient);
				// First retrieve the file from the API.
				$file = $oDrive->files->get($aData['Name']);

				// File's new metadata.
				$file->setTitle($aData['NewName']);

				$additionalParams = array();

				try 
				{
					$oDrive->files->update($aData['Name'], $file, $additionalParams);
					$mResult = true;
				} 
				catch (Exception $ex) 
				{
					$mResult = false;
				}
			}
		}
	}	

	/**
	 * @param \CAccount $oAccount
	 */
	public function onAfterMove(&$aData, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aData['FromType'] === self::$sService)
		{
			$oClient = $this->GetClient();
			if ($oClient)
			{
				$mResult = false;

				$aData['FromPath'] = $aData['FromPath'] === '' ?  'root' :  trim($aData['FromPath'], '/');
				$aData['ToPath'] = $aData['ToPath'] === '' ?  'root' :  trim($aData['ToPath'], '/');

				$oDrive = new Google_Service_Drive($oClient);

				$parent = new Google_Service_Drive_ParentReference();
				$parent->setId($aData['ToPath']);

	//			$oFile->setTitle($sNewName);
	
				foreach ($aData['Files'] as $aItem)
				{
					$oFile = $oDrive->files->get($aItem['Name']);
					$oFile->setParents(array($parent));
					try 
					{
						$oDrive->files->patch($aItem['Name'], $oFile);
						$mResult = true;
					} 
					catch (Exception $ex) 
					{
						$mResult = false;
					}
				}
			}
		}
	}	

	/**
	 * @param \CAccount $oAccount
	 */
	public function onAfterCopy(&$aData, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aData['FromType'] === self::$sService)
		{
			$oClient = $this->GetClient();
			if ($oClient)
			{
				$mResult = false;
				$oDrive = new Google_Service_Drive($oClient);

				$aData['ToPath'] = $aData['ToPath'] === '' ?  'root' :  trim($aData['ToPath'], '/');

				$parent = new Google_Service_Drive_ParentReference();
				$parent->setId($aData['ToPath']);

				$copiedFile = new Google_Service_Drive_DriveFile();
	//			$copiedFile->setTitle($sNewName);
				$copiedFile->setParents(array($parent));

				foreach ($aData['Files'] as $aItem)
				{
					try 
					{
						$oDrive->files->copy($aItem['Name'], $copiedFile);
						$mResult = true;
					} 
					catch (Exception $ex) 
					{
						$mResult = false;
					}				
				}
			}
		}
	}		
	
	public function onPopulateFileItem($aArgs, &$oItem)
	{
		if ($oItem->IsLink)
		{
			if (false !== strpos($oItem->LinkUrl, 'drive.google.com'))
			{
				$oItem->LinkType = 'google';

				$oFileInfo = $this->GetLinkInfo($oItem->LinkUrl);
				if ($oFileInfo)
				{
					if (isset($oFileInfo->thumbnailLink))
					{
						$oItem->Thumb = true;
						$oItem->ThumbnailLink = $oFileInfo->thumbnailLink;
					}
					if ($oFileInfo->mimeType === "application/vnd.google-apps.folder")
					{
						$oItem->MainAction = 'list';
						$oItem->Thumb = true;
						$oItem->ThumbnailLink = \MailSo\Base\Http::SingletonInstance()->GetFullUrl() . 'modules/' . $this->GetName() . '/images/drive.png';
					}
					else
					{
						$oItem->Size = isset($oFileInfo->fileSize) ? $oFileInfo->fileSize : $oItem->Size;
					}
				}				
				return true;
			}
		}
	}	
	
	protected function PopulateGoogleDriveFileInfo(&$oFileInfo)
	{
		if ($oFileInfo->mimeType !== "application/vnd.google-apps.folder" && !isset($oFileInfo->downloadUrl))
		{
			switch($oFileInfo->mimeType)
			{
				case 'application/vnd.google-apps.document':
					if (is_array($oFileInfo->exportLinks))
					{
						$oFileInfo->downloadUrl = $oFileInfo->exportLinks['application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
					}
					else
					{
						$oFileInfo->downloadUrl = $oFileInfo->exportLinks->{'application/vnd.openxmlformats-officedocument.wordprocessingml.document'};
					}
					$oFileInfo->title = $oFileInfo->title . '.docx';
					break;
				case 'application/vnd.google-apps.spreadsheet':
					if (is_array($oFileInfo->exportLinks))
					{
						$oFileInfo->downloadUrl = $oFileInfo->exportLinks['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
					}
					else
					{
						$oFileInfo->downloadUrl = $oFileInfo->exportLinks->{'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'};
					}
					$oFileInfo->title = $oFileInfo->title . '.xlsx';
					break;
				case 'application/vnd.google-apps.drawing':
					if (is_array($oFileInfo->exportLinks))
					{
						$oFileInfo->downloadUrl = $oFileInfo->exportLinks['image/png'];
					}
					else
					{
						$oFileInfo->downloadUrl = $oFileInfo->exportLinks->{'image/png'};
					}
					$oFileInfo->title = $oFileInfo->title . '.png';
					break;
				case 'application/vnd.google-apps.presentation':
					if (is_array($oFileInfo->exportLinks))
					{
						$oFileInfo->downloadUrl = $oFileInfo->exportLinks['application/vnd.openxmlformats-officedocument.presentationml.presentation'];
					}
					else
					{
						$oFileInfo->downloadUrl = $oFileInfo->exportLinks->{'application/vnd.openxmlformats-officedocument.presentationml.presentation'};
					}
					$oFileInfo->title = $oFileInfo->title . '.pptx';
					break;
			}
		}
	}
	
	protected function GetIdByLink($sLink)
	{
		$matches = array();
		preg_match("%https://\w+\.google\.com/\w+/d/(.*?)/.*%", $sLink, $matches);
		if (!isset($matches[1]))
		{
			preg_match("%https://\w+\.google\.com/open\?id=(.*)%", $sLink, $matches);
		}

		return isset($matches[1]) ? $matches[1] : '';			
	}
	
	protected function GetLinkInfo($sLink, $bLinkAsId = false)
	{
		$mResult = false;
		$sGDId = '';
		if ($bLinkAsId)
		{
			$sGDId = $sLink;
		}
		else
		{
			$sGDId = $this->GetIdByLink($sLink);
		}
		
		if ($sGDId !== '')
		{
			$oFileInfo = $this->_getFileInfo($sGDId);
			if ($oFileInfo)
			{
				$this->PopulateGoogleDriveFileInfo($oFileInfo);
				$mResult = $oFileInfo;
			}
			else
			{
				$mResult = false;
			}
		}
		else
		{
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
		$iUserId = \CApi::getAuthenticatedUserId();

		$aScope = array(
			'Name' => 'filestorage',
			'Description' => $this->i18N('SCOPE_FILESTORAGE', $iUserId),
			'Value' => false
		);
		if ($aArgs['OAuthAccount'] instanceof \COAuthAccount)
		{
			$aScope['Value'] = $aArgs['OAuthAccount']->issetScope('filestorage');
		}
		$mResult['Scopes'][] = $aScope;
	}	
	
	public function onAfterCheckUrl(&$aArgs, &$aReslult)
	{
		
	}
}
