#!/usr/bin/php4 -q
#
# This script will create zip and tar.gz archives for TYPO3
#
<?php

$package_creator = new package_creator;
$package_creator->start();

class package_creator {

	var $currentDir;				// Start here
	var $version;					// The version number of the TYPO3 source
	var $workSource;				// TODO: description
	
		// Customizable variables
	var $sourceDir		= 'incoming/source/typo3_src-3.6.0';	// Location of the source
	var $sitesDir		= 'incoming/sites';	// Location of the various (source) sites
	var $docDir		= 'incoming/doc';	// Location of the documentation files
	var $tempDir		= 'temp';		// We need a temporary directory
	var $targetDir		= 'target';		// Where to place the created archives
	var $verbose		= 2;			// Verbosity level (1=only important messages, 2=all messages)
	var $sitePermissionMask	= '755';		// This value is passed to the 'chmod' command which is recurively used for all site directories
	var $sourcePermissionMask = 'go-w';		// This value is passed to the 'chmod' command which is recurively used for the source directory
	var $archiveExtList	= 'tar.gz,zip';		// Archive types you want to be generated
	var $doNotCleanUp	= false;		// Skip cleaning of the tempDir and the targetDir when starting
	var $gzipSwitch		= 'z';			// Option for using gzip compression with tar
	var $bzip2Switch	= 'j';			// Option for using bzip2 compression with tar
	
	function start() {
			// Import config file which can override our default vars
		@include 'config.php';
		
		if(!$this->doNotCleanUp) $this->cleanUp(array('temporary' => $this->tempDir,'target' => $this->targetDir,));
		
		$this->currentDir = getcwd();	// Save the pwd for later usage	// TODO: check
		$this->workSource = $this->tempDir.'/'.basename($this->sourceDir);
		
			// Detect the version number
		$this->version = preg_replace('/^(.*typo3.src.)(.*)$/','$2',$this->sourceDir);
			
			// Create a temporary directory
			// Create the target directory (where we finally place the archive files)
		$directories=array('temporary' => $this->tempDir, 'target' => $this->targetDir);
		foreach($directories as $label => $dirname) {
			if(!file_exists($dirname)) $this->createDir($dirname, $label);
		}
		
			// Copy the source to the temporary directory
		if(file_exists($this->sourceDir)) {
			$this->createSource();
			$this->createSymlinks();
		} else {
			die('Error: There is no TYPO3 source in "'.$this->sourceDir.'"!'."\n");
		}
		
		$this->targetSites = $this->listDir($this->sitesDir.'/'.$this->version);
		
			// Detect the sites that have to be created
		$newTargetSites = array();
		foreach($this->targetSites as $sourceSite) {
			$targetSite = $sourceSite.'-'.$this->version;
			$this->createSite($sourceSite, $targetSite);
			$newTargetSites[]=$targetSite;
		}
		unset($this->targetSites);
		
		$this->targetSites = $newTargetSites;
		
			// Remove unused files and change the permissions
		$this->removeUnusedFiles();
		$this->fixPermissions();
		
			// Add the typo3_src to the list of sites to be packages
		foreach(explode(",",$this->archiveExtList) as $ext) {
			$this->createArchive(basename($this->sourceDir),$ext);
		}

		foreach($this->targetSites as $site) {
			foreach(explode(",",$this->archiveExtList) as $ext) {
				$this->createArchive($site,$ext);
			}
		}

		if(!$this->doNotCleanUp) {
			$this->cleanUp(array('temporary' => 'temp'));
		}
		
		echo "\nFinished.\n\n";
		exit;
	}

		// Remove all CVS directories
	function removeUnusedFiles() {
		$this->logMessage('Removing unused files and directories');
		
		$filelist = array();
		$filelist[] =`find $this->workSource -name CVS`;
		$filelist[] =`find $this->workSource -regex ".*#.*"`;
		$filelist[] =$this->workSource.'/CVSreadme.txt';
		$filelist[] =$this->workSource.'/create_symlinks.sh';
		$filelist[] =$this->workSource.'/typo3/dev/';
		$filelist[] =$this->workSource.'/typo3/icons/';
		$filelist[] =$this->workSource.'/typo3/sysext/sv/';
		
		foreach($filelist as $list) {
			foreach(explode("\n",trim($list)) as $file) {
				if(file_exists($file)) exec('rm -r '.$file);
			}
		}
		
		$this->reportStatus(true);	// TODO: check
	}

	function fixPermissions() {
		$this->logMessage('Fixing file permissions');
			
	// Site permissions
		
			// All sites belong to www-data.www-data
		$username=trim(exec('whoami'));
		if($username=='root') exec('chown -R www-data.www-data '.$this->tempDir);
		else $this->reportError('You are not root',false);
		
			// Set readonly permissions for everyone except root
		$command = 'chmod -R '.$this->sitePermissionMask.' '.$this->tempDir;
		exec($command);
		
		$command = 'find '.$this->tempDir.' -type f -exec chmod a-x {} \;';
		exec($command);

			// Make 'fileadmin', 'typo3conf', 'typo3temp', 'uploads' writable for all sites
		foreach (explode(',', 'fileadmin,typo3conf,typo3temp,uploads') as $dirname) {
			$command = 'find '.$this->tempDir.' -type d -name '.$dirname.' -exec chmod -R a+w {} \;';
			exec($command);
		}
		
	// Source permissions (overrides site permissions from above)
		
			// The whole source belongs to root.root
		$username=trim(exec('whoami'));
		if($username=='root') exec('chown -R root.root '.$this->workSource);
		else $this->reportError('You are not root',false);
		
			// Set readonly permissions for everyone except root
		$command = 'chmod -R '.$this->sourcePermissionMask.' '.$this->workSource;
		exec($command);
		
			// Some files should be executable
		$filelist = array(
			'typo3/ext/direct_mail/mod/dmailerd.phpcron',
			'typo3/ext/direct_mail/mod/returnmail.phpsh',
			'typo3/ext/phpmyadmin/modsub/phpMyAdmin-2.4.0-rc1/lang/add_message.sh',
			'typo3/ext/phpmyadmin/modsub/phpMyAdmin-2.4.0-rc1/lang/add_message_file.sh',
			'typo3/ext/phpmyadmin/modsub/phpMyAdmin-2.4.0-rc1/lang/check_lang.sh',
			'typo3/ext/phpmyadmin/modsub/phpMyAdmin-2.4.0-rc1/lang/remove_message.sh',
			'typo3/ext/phpmyadmin/modsub/phpMyAdmin-2.4.0-rc1/lang/sort_lang.sh',
			'typo3/ext/phpmyadmin/modsub/phpMyAdmin-2.4.0-rc1/lang/sync_lang.sh',
			'typo3/ext/phpmyadmin/modsub/phpMyAdmin-2.4.0-rc1/scripts/convertcfg.pl',
			'typo3/ext/phpmyadmin/modsub/phpMyAdmin-2.4.0-rc1/scripts/create-release.sh',
			'typo3/ext/phpmyadmin/modsub/phpMyAdmin-2.4.0-rc1/scripts/extchg.sh',
			'typo3/ext/phpmyadmin/modsub/phpMyAdmin-2.4.0-rc1/scripts/remove_control_m.sh',
		);
		foreach($filelist as $file) {
			$filename = $this->workSource.'/'.$file;
			if(file_exists($filename)) exec('chmod a+x '.$filename);
		}
			// Remove public access for Readme files in the root of the source
		$filelist = array(
			'CVSreadme.txt',
			'GPL.txt',
			'INSTALL.txt',
			'LICENSE.txt',
			'PACKAGE.txt',
			'README.txt',
			'TODO.txt',
			'ChangeLog',
			'Changelog.package',
			'Changelog',
			'changelog.txt',
			'create_symlinks.sh',
		);
		foreach($this->listDir($this->tempDir) as $dirname) {
			foreach(explode(",",$filelist) as $file) {
				$filename = $this->tempDir.'/'.$dirname.'/'.$file;
				if(file_exists($filename)) exec('chmod go-rwx '.$filename);
			}
		}
		
		$this->reportStatus(true);	// TODO: check
	}

	function cleanUp($directories) {
			
			// Remove the temporary/target directory
		foreach($directories as $label => $dirname) {
			if(file_exists($dirname)) {
				$this->logMessage('Cleaning up the "'.$label.'" dir');
			
				$command="rm -r ".$dirname;
				$status=`$command`;
			
				$this->reportStatus(true);	// TODO: check
			}
		}
	}
	
		// Create the archives
	function createArchive($siteName,$ext="tar.gz") {
		$archiveName=$this->targetDir.'/'.$siteName.'.'.$ext;
		
		$this->logMessage('Creating archive '.basename($archiveName));

		$docPath = str_replace('-'.$this->version, '', $siteName);
		
		$docFiles = array();
		foreach($this->listDir($this->docDir.'/'.$this->version.'/'.$ext.'/'.$docPath) as $docFileName) {
			$docFiles[] = $docFileName;
		}
		foreach($this->listDir($this->docDir.'/'.$this->version.'/'.$ext.'/_all') as $docFileName) {
			$docFiles[] = $docFileName;
		}
		
		if(!file_exists($archiveName)) {
			
				// Add additional documentation for this site
			$replacedFiles = array();
			foreach($docFiles as $docFile) {
				$sourceFile = $this->docDir.'/'.$this->version.'/'.$ext.'/'.$docPath.'/'.$docFile;
				$destFile = $this->tempDir.'/'.$siteName.'/'.$docFile;
				
				if(file_exists($sourceFile)) {
					if(!file_exists($destFile)) {
						$command='cp -LR '.$sourceFile.' '.$destFile;
						$status=`$command`;
						$replacedFiles[]=basename($destFile);
					} else {
						if($this->verbose >= 0) $this->reportError('Warning: File '.$destFile.' already exists!',false);
					}
				}
			}
			
				// Some files should be executable
			$filelist = 'make_secure.sh';
			foreach(explode(',',$filelist) as $file) {
				$filename = $this->tempDir.'/'.$siteName.'/'.$file;
				if(file_exists($filename)) exec('chmod a+x '.$filename);
			}
			
			switch($ext) {
				case "tar.gz":
				case "tar.bz2":
					if($ext=='tar.gz') $compressorSwitch=$this->gzipSwitch;
					else $compressorSwitch=$this->bzip2Switch;
					
					if($this->verbose > 2) $v='v';	// enable quiet mode when calling zip
					$command='cd '.$this->tempDir.' && tar c'.$compressorSwitch.$v.'f ../'.$archiveName.' '.$siteName.' && cd ..';
					$status=`$command`;
					
					if($this->verbose >= 0 && $status!='') $this->reportError($status,false);
				break;
				case "zip":
					$sourceDocFiles = array();
					if(file_exists($this->tempDir.'/'.$siteName.'/typo3_src')) {
						// We are inside of a site directory
						
							// Add all files from the root of the typo3_src dir since we won't add typo3_src
						$filelist =`find $this->workSource -type f -maxdepth 1`;
						foreach(explode("\n",trim($filelist)) as $file) {
							$sourceDocFiles[]=$file;
						}
						
						foreach($sourceDocFiles as $filename) {
							if(file_exists($filename)) {
								$command='cd '.$this->tempDir.' && cp ../'.$filename.' '.$siteName.'/ && cd ..';
								$status=`$command`;
							} else {
								if($this->verbose >= 0) $this->reportError('Warning: File '.$filename.' already exists!',false);
							}
						}
					}
					
					if($this->verbose < 3) $v='q';	// enable quiet mode when calling zip
						// Call zip command, exclude 'typo3_src' directory since it's not needed
					$command='cd '.$this->tempDir.' && zip -9q'.$v.'r ../'.$archiveName.' '.$siteName.' -x '.$siteName.'/typo3_src/\* && cd ..';
					$status=`$command`;
					foreach($sourceDocFiles as $filename) {
						$command='cd '.$this->tempDir.'/'.$siteName.' && rm '.basename($filename).' && cd ..';
						$status=`$command`;
					}
					
					if($this->verbose >= 0 && $status!='') $this->reportError($status,false);
				break;
				default:
					$this->reportError('Unknown file extension: '.$ext);
				break;
			}
			
				// Remove the doc files
			foreach($replacedFiles as $docFile) {
				$destFile = $this->tempDir.'/'.$siteName.'/'.$docFile;
				
				if(file_exists($destFile)) {
					$command='rm '.$destFile;
					$status=`$command`;
				}
			}
			
			$this->reportStatus(true);
			
		} else {
			$message = 'File '.$archiveName.' already exists!';
			$this->reportError($message);
		}
		
	}
	
		// Copy the site directory from $this->sitesDir to $this->tempDir
	function createSite($sourceSite, $targetSite) {
		$this->logMessage('Creating site '.$targetSite);
		
		if(!file_exists($this->tempDir.'/'.$targetSite)) {
			$command='mkdir '.$this->tempDir.'/'.$targetSite.' && cp -R '.$this->sitesDir.'/'.$this->version.'/'.$sourceSite.'/* '.$this->tempDir.'/'.$targetSite.'/';
			$status=`$command`;
		}
		
		if(!file_exists($this->tempDir.'/'.$targetSite.'/typo3_src')) {
			$linkSource = '../'.basename($this->workSource);
			$linkTarget = 'typo3_src';
			$command = 'cd '.$this->tempDir.'/'.$targetSite.' && ln -s '.$linkSource.' '.$linkTarget;
			$status=`$command`;
		}
		
		$this->logMessage('Updating its database:'.trim($status));
		$command = './database-updater.php '.$this->tempDir.'/'.$targetSite.'/';
		$status=`$command`;

		$this->reportStatus(true);	// TODO: check
	}
	
		// Create a directory and show the output
	function createDir($directory, $label='') {
		$this->logMessage('Creating directory '.$label);
		
		if(mkdir($directory, 0755)) {
			$this->reportStatus(true);
			return true;
		} else {
			$this->reportStatus(false);
			return false;
		}
	}
	
		// TODO: description
	function createSource() {
		$this->logMessage('Copying source into temp directory');
		
		if(!file_exists($this->workSource)) {
			$command='cp -R '.$this->sourceDir.' '.$this->workSource;
			$status=`$command`;
		}
		
		$this->reportStatus(true);	// TODO: check
	}
	
		// Create symbolics link if required
	function createSymlinks() {
		$this->logMessage('Creating symbolic links if required');
		
		$linkArr = array(
			array('', 'tslib', 'typo3/sysext/cms/tslib'),
			array('typo3/', 't3lib', '../t3lib'),
			array('typo3/', 'thumbs.php', '../t3lib/thumbs.php'),
			array('typo3/', 'gfx', '../t3lib/gfx'),
			array('t3lib/fonts/', 'verdana.ttf', 'vera.ttf'),
			array('t3lib/fonts/', 'arial.ttf', 'nimbus.ttf'),
		);
		
		foreach($linkArr as $linkArrRow) {
			list($linkDir, $linkTarget, $linkSource) = $linkArrRow;
			if(!file_exists($this->workSource.'/'.$linkDir.'/'.$linkTarget)) {
				$command = 'cd '.$this->workSource.'/'.$linkDir.' && ln -s '.$linkSource.' '.$linkTarget;
				exec($command);
			}
		}
		
		$this->reportStatus(true);	// TODO: check
	}
	
		// TODO: description
	function listDir($directory) {
		$returnArr = array();
		
		if(file_exists($directory)) {
			$handle=opendir($directory);
			while($file=readdir($handle)) {
				if($file!="." && $file!="..") $returnArr[]=$file;
			}
			closedir($handle);
		}
		
		return($returnArr);
	}
	
		// TODO: description
	function logMessage($message) {
		if($this->verbose >= 2) {
			echo str_pad($message.' ', 90, '.').': ';
		}
	}
	
		// TODO: description
	function reportStatus($status) {
		if($this->verbose >= 2) {
			if($status) echo "OK";
			else echo "Failed";
			echo "\n";
		}
	}
	
	function reportError($message,$stop=true) {
		echo "\n\n";
		echo 'Error: '.$message."\n";
		if($stop) die();
	}
}
?>