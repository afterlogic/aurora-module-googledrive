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
		
		$this->subscribeEvent('Files::PopulateFileItem', array($this, 'onPopulateFileItem'));
		$this->subscribeEvent('Google::GetSettings', array($this, 'onGetSettings'));
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
	
	protected function GetClient($sType)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		$mResult = false;
		if ($sType === self::$sService)
		{
			$oOAuthIntegratorWebclientModule = \CApi::GetModuleDecorator('OAuthIntegratorWebclient');
			$oSocialAccount = $oOAuthIntegratorWebclientModule->GetAccount($sType);
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
	
	/**
	 * @param \CAccount $oAccount
	 */
	public function onAfterGetFileInfo($aArgs)
	{
		$mResult = false;
		if ($aArgs['Type'] === self::$sService)
		{
			\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);

			$oClient = $this->GetClient($aArgs['Type']);
			if ($oClient)
			{
				$oDrive = new Google_Service_Drive($oClient);
				$oFile = $oDrive->files->get($aArgs['Name']);
				$mResult = $this->PopulateFileInfo($aArgs['Type'], $aArgs['Path'], $oFile);
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
			$oClient = $this->GetClient($aArgs['Type']);
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
	
	/**
	 * @param \CAccount $oAccount
	 */
	public function onAfterGetFiles($aArgs, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		if ($aArgs['Type'] === self::$sService)
		{
			$mResult['Items'] = array();
			$oClient = $this->GetClient($aArgs['Type']);
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

	/**
	 * @param \CAccount $oAccount
	 */
	public function onAfterCreateFolder(&$aData, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aData['Type'] === self::$sService)
		{
			$oClient = $this->GetClient($aData['Type']);
			if ($oClient)
			{
				$mResult = false;

				$folder = new Google_Service_Drive_DriveFile();
				$folder->setTitle($aData['FolderName']);
				$folder->setMimeType('application/vnd.google-apps.folder');

				// Set the parent folder.
				if ($aData['Path'] != null) 
				{
				  $parent = new Google_Service_Drive_ParentReference();
				  $parent->setId($aData['Path']);
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
			$oClient = $this->GetClient($aArgs['Type']);
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
			$oClient = $this->GetClient($aData['Type']);
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
			$oClient = $this->GetClient($aData['Type']);
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
			$oClient = $this->GetClient($aData['FromType']);
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
			$oClient = $this->GetClient($aData['FromType']);
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
				$oGoogleAuthWebclientModule = \CApi::GetModule('GoogleAuthWebclient');
				if ($oGoogleAuthWebclientModule)
				{
					$sKey = $oGoogleAuthWebclientModule->GetConfig('Key');
				}
				$oFileInfo = $this->GetLinkInfo($oItem->LinkUrl, $sKey);
				if ($oFileInfo)
				{
					$oItem->Name = isset($oFileInfo->title) ? $oFileInfo->title : $oItem->Name;
					$oItem->Size = isset($oFileInfo->fileSize) ? $oFileInfo->fileSize : $oItem->Size;
					if (isset($oFileInfo->thumbnailLink))
					{
						$oItem->Thumb = true;
						$oItem->ThumbnailLink = $oFileInfo->thumbnailLink;
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
	
	protected function GetLinkInfo($sLink, $sGoogleAPIKey, $sAccessToken = null, $bLinkAsId = false)
	{
		$mResult = false;
		$sGDId = '';
		if ($bLinkAsId)
		{
			$sGDId = $sLink;
		}
		else
		{
			$matches = array();
			preg_match("%https://\w+\.google\.com/\w+/d/(.*?)/.*%", $sLink, $matches);
			if (!isset($matches[1]))
			{
				preg_match("%https://\w+\.google\.com/open\?id=(.*)%", $sLink, $matches);
			}
			
			$sGDId = isset($matches[1]) ? $matches[1] : '';	
		}
		
		if ($sGDId !== '')
		{
			$sUrl = "https://www.googleapis.com/drive/v2/files/".$sGDId.'?key='.$sGoogleAPIKey;
			$aHeaders = $sAccessToken ? array('Authorization: Bearer '. $sAccessToken) : array();

			$sContentType = '';
			$iCode = 0;

			$mResult = \MailSo\Base\Http::SingletonInstance()->GetUrlAsString($sUrl, '', $sContentType, $iCode, null, 10, '', '', $aHeaders);
			if ($iCode === 200)
			{
				$mResult = json_decode($mResult);	
				$this->PopulateGoogleDriveFileInfo($mResult);
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
}
