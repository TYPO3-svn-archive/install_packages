<?php

// Sebastian Kurfuerst
// Work in progress...
// Needed to finish:
	// in $this->uploadToSourceforge, add list of files to upload (extend the array)
	//TYPO3_BRANCH update
// only commit changelog
// typo_version uU erhoehen// remove the comments from all exec() or $this->exec() calls
class packaging {
	var $information;
	var $baseSVN = 'https://typo3.svn.sourceforge.net/svnroot/typo3/TYPO3core/';
	var $copherArgs = ' --project=typo3 --group-id=20391 --package="TYPO3 Source" --package-id=14557 --hidden';
	function start()	{
		$this->preReleaseCheck();
		$this->fetchInformation();
		$this->updateChangeLog();
		$this->createSVNtag();

		$this->package();

		$this->createDiffstat();
		$this->uploadToSourceforge();

		$this->postRelease_updateTypoVersion();
		$this->displayEMailTemplate();
	}
	function preReleaseCheck()	{
		$this->headers('Pre-release checklist');
		echo <<<EOF
* Did you update NEWS.txt?
* In case of a new major/minor release: 
	* Have you updated the login images for t3skin and default?
	-> If not, mail to Rasmus Skjoldan (rasmus at bee3.com)
	* Have you updated the tsconfig_help with latest data from 
	  doc_core_tsref and doc_core_tsconfig? 
	-> If not, use the Help->TypoScript Help module to generate
	   the SQL data. Ask Francois Suter <fsuter at cobweb.ch>
	   when in doubt.
* Have the release notes been written?
	-> If not, mail to Thomas Esders (pechgehabt at gmail.com)
(Idea by Ingmar, 02.12.07: We should always update the ext_emconf.php
 files of all sysexts before releasing new versions, because otherwise
 the EM shows "A difference between the originally installed version 
 and the current was detected!" if files have been changed, which 
 confuses the user and makes it impossible to see if there have really
 been manual changes.)
* Is the press release ready?
	-> If not, mail to Sander Vogels (press at typo3.org)
* Did you create the new branch in case of a minor release? This
  script does *not* do it for you automatically!
* Add the new version to the "TYPO3 Core" project in the Bugtracker.

EOF;
		if ($this->askQuestion('Is everything correct? (y/n)') != 'y')
			$this->assertError();
	}

	function fetchInformation()	{
		$this->headers('Getting needed information...');
		$this->information['patchLevelRelease'] = $this->askQuestion('Patch level release? (y,n)')=='y'?1:0;
		$this->information['sf_user'] = $this->askQuestion('Enter SF.net username');
		exec('stty -echo'); // do not show the password on the shell
		$this->information['sf_pass'] = $this->askQuestion('Enter SF.net password');
		exec('stty echo');
		$this->information['name'] = $this->askQuestion('Enter your name');
		$this->information['email'] = $this->askQuestion('Enter your email adress');
		$this->information['versionNumber'] = $this->askQuestion('Which version number should be packaged? (f.e. 4.1.1 or 4.1.0 or 4.1.0beta3 or 4.2.0RC1)');
		$this->information['nextVersion'] = $this->askQuestion('NEW!!! Enter next version number (e.g. 4.1.7). This will be appended with "-dev" and used as the version number in the branch (or trunk) AFTER this version is released.');
		$this->information['branch'] = str_replace('.', '-', $this->askQuestion('Which branch should be checked out? (f.e. 4.0 or trunk, please enter exactly like in the example)'));
		$this->information['previousVersions'] = $this->askQuestion('DIFFSTAT: enter previous versions (comma seperated, f.e. 4.0.0 or 4.0.0beta3)');
		$passwordTemp = $this->information['sf_pass'];
		$this->information['sf_pass'] = 'PASSWORD';
		print_r($this->information);
		$this->information['sf_pass'] = $passwordTemp;
		unset($passwordTemp);
		if ($this->askQuestion('Is everything correct? (y/n)') != 'y')	{
			$this->writeMessage('re-running "fetch information"');
			$this->fetchInformation();
		}
	}

	function updateChangeLog()	{
			// clean up working directory
		$this->writeMessage('Cleaning up working directory');
		$this->exec("rm -Rf work/*; rm -Rf work/.*");
			// SVN checkout
		if ($this->information['branch'] == 'trunk')
			$svnRoot = $this->baseSVN.'trunk';
		else
			$svnRoot = $this->baseSVN.'branches/TYPO3_'.$this->information['branch'];

		$this->writeMessage('Checking out SVN from '.$svnRoot);
		$this->writeMessage('Notice: you might need to press "enter" and accept the SSL certificate afterwards.');
		$this->exec('svn co '.$svnRoot.' work');

			// read changeLog
		$this->writeMessage('Updating ChangeLog');
		$changeLog = file_get_contents('work/ChangeLog');
			// add ChangeLog entry
		$newChangeLog = strftime('%Y-%m-%d').'  '.$this->information['name'].'  <'.$this->information['email'].">\n\n";
		$newChangeLog .= chr(9).'* Release of TYPO3 '.$this->information['versionNumber']."\n\n".$changeLog;
			// write changeLog
		$fp = fopen('work/ChangeLog', 'w');
		fwrite($fp, $newChangeLog);
		fclose($fp);
		
		    // SECOND STEP: update TYPO_VERSION
		$this->updateTypoVersion($this->information['versionNumber']);
		
			// SVN commit
		$this->writeMessage('Committing to SVN');
		$this->exec('cd work; svn commit --username '.$this->information['sf_user'].' --password '.$this->information['sf_pass'].' --message "Release of TYPO3 '.$this->information['versionNumber'].'";cd ..');
	}

	function updateTypoVersion($targetVersion) {
		$this->writeMessage('Updating TYPO_VERSION to '.$targetVersion.' in t3lib/config_default.php');
		$configDefault = file_get_contents('work/t3lib/config_default.php');
			// change version number
		$newConfigDefault = preg_replace('/(\$TYPO_VERSION = )\'[^\']+/', '$1\''.$targetVersion, $configDefault);
		
			// write t3lib/config_default.php
		$fp = fopen('work/t3lib/config_default.php', 'w');
		fwrite($fp, $newConfigDefault);
		fclose($fp);
	}

	function createSVNtag()	{
		$this->headers('Creating SVN Tag');
		if ($this->information['branch'] == 'trunk')	{
			$branchPath = 'trunk/';
		} else {
			$branchPath = 'branches/TYPO3_'.$this->information['branch'];
		}
		$this->exec('cd work; svn copy --username '.$this->information['sf_user'].' --password '.$this->information['sf_pass'].' --message "Tagging TYPO3 '.$this->information['versionNumber'].'" '.$this->baseSVN.$branchPath.' '.$this->baseSVN.'tags/TYPO3_'.str_replace('.','-',$this->information['versionNumber']).' ; cd ..');
		$this->writeMessage('successful!');
	}

		// This is updating the TYPO_VERSION again after the release has been tagged. If you e.g. just released version 4.1.3, the TYPO_VERSION will be set to 4.1.4-dev in this step
	function postRelease_updateTypoVersion() {
		$nextVersionString = $this->information['nextVersion'].'-dev';
		$this->updateTypoVersion($nextVersionString);
		$this->writeMessage('Committing to SVN');
		$this->exec('cd work; svn commit --username '.$this->information['sf_user'].' --password '.$this->information['sf_pass'].' --message "Updating version number to  '.$nextVersionString.' after release of '.$this->information['versionNumber'].'"; cd ..');
	}

	function package()	{
		$this->headers('Packaging');
		$this->exec('rm -Rf packaging/incoming/source;mkdir packaging/incoming/source;cp -Ra work/* packaging/incoming/source');
		$this->exec('cd packaging; fakeroot bash ./package_creator.sh; cd ..');
	}

	function createDiffstat()	{
		$this->headers('Creating diffstat');
		$this->exec('rm -Rf diffstat/*');
		$versions = explode(',',$this->information['previousVersions']);
		if (!count($versions)) return;
		foreach ($versions as $version)	{
			$this->writeMessage('Downloading and extracting version '.$version);
			$version = trim($version);
			$this->exec('cd diffstat; wget -T 5 http://downloads.sourceforge.net/typo3/typo3_src-'.$version.'.tar.gz; tar -xzvf typo3_src-'.$version.'.tar.gz');

			$this->writeMessage('creating diff and saving it to diffstat/diff-tmp.diff');
			$this->exec('diff -ruN --exclude=".svn" diffstat/typo3_src-'.$version.' work/ > diffstat/diff-tmp.diff');

			$this->writeMessage('Creating diffstat and saving it to diffstat/diffstat-'.$version.'-'.$this->information['versionNumber'].'.txt');
			$this->exec('diffstat diffstat/diff-tmp.diff > diffstat/diffstat-'.$version.'-'.$this->information['versionNumber'].'.txt');
			$this->exec('rm -f diffstat/diff-tmp.diff');
		}


	}

	function uploadToSourceforge()	{
		$this->headers('Upload to sourceforge');
		$files = array(
		    'dummy-'.$this->information['versionNumber'].'.tar.gz',
    		    'dummy-'.$this->information['versionNumber'].'.zip',
		    'typo3_src-'.$this->information['versionNumber'].'.tar.gz',
    		    'typo3_src-'.$this->information['versionNumber'].'.zip',
    		    'typo3_src+dummy-'.$this->information['versionNumber'].'.zip',
		);
		$files = 'packaging/target/'.implode(' packaging/target/',$files);
		// TODO: copy md5sums and add text: "See README.txt for details. MD5 checksums:"
		$this->exec('copher/copher.pl '.$this->copherArgs.' --user='.$this->information['sf_user'].' --password='.$this->information['sf_pass'].' --release="TYPO3 '.$this->information['versionNumber'].'" --date='.strftime('%Y-%m-%d').' --notes=packaging/target/md5sums.txt --changelog=work/NEWS.txt '.$files);
	}

	function displayEMailTemplate() {
		echo '
================= Announcement Template ==================
Dear TYPO3 users,

TYPO3 version '.$this->information['versionNumber'].' is ready for download. It is a maintenance release
of version 4.1 and therefore contains only bugfixes.

For details about the release, see:
http://wiki.typo3.org/index.php/'.$this->information['versionNumber'].'

MD5 checksums:
'.file_get_contents('packaging/target/md5sums.txt').'

Download:
http://typo3.org/download/packages/
==========================================================

Next steps:
 - update the download links at typo3.org
 - create the version number in the bugtracker if not already done
 - send out the announcement to the TYPO3 Announce list
 - send out the announcement to the dev, teams.core, german and english Newsgroup

';
	}

	function headers($text)	{
		echo "\n\n";
		echo $text."\n";
		for($i=0;$i<40;$i++)
			echo '#';
		echo "\n";
	}

	function exec($exec_str)	{
		$question = "Do you want me to run the following command now? (y/n)\n".chr(9);
		$question .= str_replace($this->information['sf_pass'], 'PASSWORD', $exec_str);
		if ($this->askQuestion($question) == 'y')	{
			exec($exec_str);
		} else	{
				$this->headers('!!! ERROR !!!');
				$answer = $this->askQuestion('You answered a question with "no" which should be answered with "yes". To continue, enter 1. To exit, enter -1. By default, the question will be asked again.');
				switch($answer)	{
					case 1:break;
					case -1: $this->assertError();
						break;
					default:
						$this->exec($exec_str);
				}
			}
	}

	function askQuestion($question)	{
		echo "\n";
		echo $question;
		return $this->getLine();
	}

	function getLine()	{
		echo "\n> ";
		return trim(fgets(STDIN)); // reads one line from STDIN
	}

	function writeMessage($msg)	{
		echo "\n* ".$msg;
	}

	function assertError()	{
		die("\naborted!\n");
	}

}
$packaging = new packaging();

$packaging->start();
?>

