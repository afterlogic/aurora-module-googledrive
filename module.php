<?php

class GoogleDriveModule extends AApiModule
{
	protected static $sService = 'google';
	
	protected $aRequireModules = array(
		'OAuthIntegratorWebclient', 'GoogleAuthWebclient'
	);
	
	public function init() 
	{
		set_include_path(__DIR__."/classes/" . PATH_SEPARATOR . get_include_path());
		
		if (!class_exists('Google_Client'))
		{
			$this->incClass('Google/Client');
			if (!class_exists('Google_Service_Drive'))
			{
				$this->incClass('Google/Service/Drive');
				include_once 'Google/Service/Drive.php';
			}
		}		
		
		$this->subscribeEvent('Files::GetStorages::after', array($this, 'GetStorages'));
		$this->subscribeEvent('Files::GetFile', array($this, 'onGetFile'));
		$this->subscribeEvent('Files::GetFiles::after', array($this, 'GetFiles'));
		$this->subscribeEvent('Files::FileExists::after', array($this, 'FileExists'));
		$this->subscribeEvent('Files::GetFileInfo::after', array($this, 'GetFileInfo'));
		$this->subscribeEvent('Files::GetFile::after', array($this, 'GetFile'));
		$this->subscribeEvent('Files::CreateFolder::after', array($this, 'CreateFolder'));
		$this->subscribeEvent('Files::CreateFile', array($this, 'onCreateFile'));
		$this->subscribeEvent('Files::CreatePublicLink::after', array($this, 'CreatePublicLink'));
		$this->subscribeEvent('Files::DeletePublicLink::after', array($this, 'DeletePublicLink'));
		$this->subscribeEvent('Files::Delete::after', array($this, 'Delete'));
		$this->subscribeEvent('Files::Rename::after', array($this, 'Rename'));
		$this->subscribeEvent('Files::Move::after', array($this, 'Move'));
		$this->subscribeEvent('Files::Copy::after', array($this, 'Copy')); 
		
/*
		$this->subscribeEvent('OAuthIntegratorAction', array($this, 'onOAuthIntegratorAction'));
		$this->subscribeEvent('GetServices', array($this, 'onGetServices'));
		$this->subscribeEvent('GetServicesSettings', array($this, 'onGetServicesSettings'));
		$this->subscribeEvent('UpdateServicesSettings', array($this, 'onUpdateServicesSettings'));
 */
	}
	
	public function GetStorages(&$aResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		$oOAuthIntegratorWebclientModule = \CApi::GetModuleDecorator('OAuthIntegratorWebclient');
		$oSocialAccount = $oOAuthIntegratorWebclientModule->GetAccount(self::$sService);

		if ($oSocialAccount instanceof COAuthAccount && $oSocialAccount->Type === self::$sService)
		{		
			$aResult['@Result'][] = [
				'Type' => self::$sService, 
				'IsExternal' => true,
				'DisplayName' => 'GoogleDrive'
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
				$oGoogleAuthWebclientModule = \CApi::GetModuleDecorator('GoogleAuthWebClient');
				if ($oGoogleAuthWebclientModule)
				{
					$oClient = new Google_Client();
					$oClient->setClientId($oGoogleAuthWebclientModule->getConfig('Id', ''));
					$oClient->setClientSecret($oGoogleAuthWebclientModule->getConfig('Secret', ''));
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
	
	/**
	 * @param \CAccount $oAccount
	 */
	public function FileExists(&$aData)
	{
		$bResult = false;
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		if ($aData['Type'] === self::$sService)
		{
			$oClient = $this->GetClient($aData['Type']);
			if ($oClient)
			{
				$bResult = true;
			}
		}

		$aData['@Result'] = $bResult;
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
			\api_Utils::PopulateGoogleDriveFileInfo($oFile);
			$mResult /*@var $mResult \CFileStorageItem */ = new  \CFileStorageItem();
			$mResult->IsExternal = true;
			$mResult->TypeStr = $sType;
			$mResult->IsFolder = ($oFile->mimeType === "application/vnd.google-apps.folder");
			$mResult->Id = $oFile->id;
			$mResult->Name = $oFile->title;
			$mResult->Path = '';
			$mResult->Size = $oFile->fileSize;
			$mResult->FullPath = $oFile->id;

//				$oItem->Owner = $oSocial->Name;
			$mResult->LastModified = date_timestamp_get(date_create($oFile->createdDate));
			$mResult->Hash = \CApi::EncodeKeyValues(array(
				'Type' => $sType,
				'Path' => $sPath,
				'Name' => $mResult->Id,
				'Size' => $mResult->Size
			));
		}

		return $mResult;
	}	
	
	/**
	 * @param \CAccount $oAccount
	 */
	public function GetFileInfo($Type, $Path, $Name)
	{
		$mResult = false;
		if ($Type === self::$sService)
		{
			\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);

			$oClient = $this->GetClient($Type);
			if ($oClient)
			{
				$oDrive = new Google_Service_Drive($oClient);
				$oFile = $oDrive->files->get($Name);
				$mResult = $this->PopulateFileInfo($Type, $Path, $oFile);
			}
		}
		
		return $mResult;
	}	
	
	/**
	 */
	public function onGetFile($UserId, $Type, $Path, $Name)
	{
		if ($Type === self::$sService)
		{
			$bResult = false;
			$oClient = $this->GetClient($Type);
			if ($oClient)
			{
				$oDrive = new Google_Service_Drive($oClient);
				$oFile = $oDrive->files->get($Name);

				\api_Utils::PopulateGoogleDriveFileInfo($oFile);
				$oRequest = new Google_Http_Request($oFile->downloadUrl, 'GET', null, null);
				$oClientAuth = $oClient->getAuth();
				$oClientAuth->sign($oRequest);
				$oHttpRequest = $oClientAuth->authenticatedRequest($oRequest);			
				if ($oHttpRequest->getResponseHttpCode() === 200) 
				{
					$bResult = fopen('php://memory','r+');
					fwrite($bResult, $oHttpRequest->getResponseBody());
					rewind($bResult);
				} 
			}
			return $bResult;
		}
	}	
	
	/**
	 * @param \CAccount $oAccount
	 */
	public function GetFiles(&$aData)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		if ($aData['Type'] === self::$sService)
		{
			$mResult = array();
			$oClient = $this->GetClient($aData['Type']);
			if ($oClient)
			{
				$mResult = array();
				$oDrive = new Google_Service_Drive($oClient);
				$sPath = ltrim(basename($aData['Path']), '/');

				$aFileItems = array();
				$sPageToken = NULL;			

				if (empty($sPath))
				{
					$sPath = 'root';
				}

				$sQuery  = "'".$sPath."' in parents and trashed = false";
				if (!empty($aData['Pattern']))
				{
					$sQuery .= " and title contains '".$aData['Pattern']."'";
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
					$oItem /*@var $oItem \CFileStorageItem */ = $this->PopulateFileInfo($aData['Type'], $aData['Path'], $oChild);
					if ($oItem)
					{
						$mResult[] = $oItem;
					}
				}
		}

			$aData['@Result']['Items'] = $mResult;
		}
	}	

	/**
	 * @param \CAccount $oAccount
	 */
	public function CreateFolder(&$aData)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aData['Type'] === self::$sService)
		{
			$oClient = $this->GetClient($aData['Type']);
			if ($oClient)
			{
				$bResult = false;

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
					$bResult = true;
				} 
				catch (Exception $ex) 
				{
					$bResult = false;
				}				
				$aData['@Result'] = $bResult;
			}
		}
	}	

	/**
	 * @param \CAccount $oAccount
	 */
	public function onCreateFile($UserId, $Type, $Path, $Name, $Data, &$Result)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($Type === self::$sService)
		{
			$oClient = $this->GetClient($Type);
			if ($oClient)
			{
				$sMimeType = \MailSo\Base\Utils::MimeContentType($Name);
				$file = new Google_Service_Drive_DriveFile();
				$file->setTitle($Name);
				$file->setMimeType($sMimeType);

				$Path = trim($Path, '/');
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
					if (is_resource($Data))
					{
						rewind($Data);
						$sData = stream_get_contents($Data);
					}
					else
					{
						$sData = $Data;
					}
					$oDrive->files->insert($file, array(
						'data' => $Data,
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
	public function Delete(&$aData)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aData['Type'] === self::$sService)
		{
			$oClient = $this->GetClient($aData['Type']);
			if ($oClient)
			{
				$bResult = false;
				$oDrive = new Google_Service_Drive($oClient);

				foreach ($aData['Items'] as $aItem)
				{
					try 
					{
						$oDrive->files->trash($aItem['Name']);
						$bResult = true;
					} 
					catch (Exception $ex) 
					{
						$bResult = false;
					}
				}

				$aData['@Result'] = $bResult;
			}
		}
	}	

	/**
	 * @param \CAccount $oAccount
	 */
	public function Rename(&$aData)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aData['Type'] === self::$sService)
		{
			$oClient = $this->GetClient($aData['Type']);
			if ($oClient)
			{
				$bResult = false;
				$oDrive = new Google_Service_Drive($oClient);
				// First retrieve the file from the API.
				$file = $oDrive->files->get($aData['Name']);

				// File's new metadata.
				$file->setTitle($aData['NewName']);

				$additionalParams = array();

				try 
				{
					$oDrive->files->update($aData['Name'], $file, $additionalParams);
					$bResult = true;
				} 
				catch (Exception $ex) 
				{
					$bResult = false;
				}
				$aData['@Result'] = $bResult;
			}
		}
	}	

	/**
	 * @param \CAccount $oAccount
	 */
	public function Move(&$aData)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aData['FromType'] === self::$sService)
		{
			$oClient = $this->GetClient($aData['FromType']);
			if ($oClient)
			{
				$bResult = false;

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
						$bResult = true;
					} 
					catch (Exception $ex) 
					{
						$bResult = false;
					}
				}
				
				$aData['@Result'] = $bResult;
			}
		}
	}	

	/**
	 * @param \CAccount $oAccount
	 */
	public function Copy(&$aData)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aData['FromType'] === self::$sService)
		{
			$oClient = $this->GetClient($aData['FromType']);
			if ($oClient)
			{
				$bResult = false;
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
						$bResult = true;
					} 
					catch (Exception $ex) 
					{
						$bResult = false;
					}				
				}
				$aData['@Result'] = $bResult;
			}
		}
	}		
	
	
}
