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
	}
	function preReleaseCheck()	{
		$this->headers('Pre-release checklist');
		echo <<<EOF
* Did you update NEWS.txt?
* Have the release notes been written?
	* If not, mail to Thomas Esders (pechgehabt at gmail.com)
* Is the press release ready?
	* If not, mail to Sander Vogels (press at typo3.org)
* Did you create the new branch in case of a minor release?

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
		exec("rm -Rf work/*; rm -Rf work/.*");
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
		$this->writeMessage('Updating TYPO_VERSION in t3lib/config_default.php');
		$configDefault = file_get_contents('work/t3lib/config_default.php');
			// change version number
		$newConfigDefault = preg_replace('/(\$TYPO_VERSION = )\'[^\']+/', '$1\''.$this->information['versionNumber'], $configDefault);
		
			// write t3lib/config_default.php
		$fp = fopen('work/t3lib/config_default.php', 'w');
		fwrite($fp, $newConfigDefault);
		fclose($fp);
		
		

			// SVN commit
		$this->writeMessage('Committing to SVN');
		$this->exec('cd work; svn commit --username '.$this->information['sf_user'].' --password '.$this->information['sf_pass'].' --message "Release of TYPO3 '.$this->information['versionNumber'].'";cd ..');
	}

	function createSVNtag()	{
		$this->headers('Creating SVN Tag');
		if ($this->information['branch'] == 'trunk')	{
		    $this->exec('cd work; svn copy --username '.$this->information['sf_user'].' --password '.$this->information['sf_pass'].' --message "Tagging TYPO3 '.$this->information['versionNumber'].'" '.$this->baseSVN.'trunk/ '.$this->baseSVN.'tags/TYPO3_'.str_replace('.','-',$this->information['versionNumber']).' ;cd ..');
		} else {
		    $this->exec('cd work; svn copy --username '.$this->information['sf_user'].' --password '.$this->information['sf_pass'].' --message "Tagging TYPO3 '.$this->information['versionNumber'].'" '.$this->baseSVN.'branches/TYPO3_'.$this->information['branch'].' '.$this->baseSVN.'tags/TYPO3_'.str_replace('.','-',$this->information['versionNumber']).' ; cd ..');
		}
		$this->writeMessage('successful!');
	}

	function package()	{
		$this->headers('Packaging');
		$this->exec('rm -Rf packaging/incoming/source;mkdir packaging/incoming/source;cp -Ra work/* packaging/incoming/source');
		$this->exec('cd packaging; fakeroot bash ./package_creator.sh; cd ..');
	}

	function createDiffstat()	{
		$this->headers('Creating diffstat');
		exec('rm -Rf diffstat/*');
		$versions = explode(',',$this->information['previousVersions']);
		if (!count($versions)) return;
		foreach ($versions as $version)	{
			$this->writeMessage('Downloading and extracting version '.$version);
			$version = trim($version);
			exec('cd diffstat; wget -T 5 http://downloads.sourceforge.net/typo3/typo3_src-'.$version.'.tar.gz; tar -xzvf typo3_src-'.$version.'.tar.gz');

			$this->writeMessage('creating diff and saving it to diffstat/diff-tmp.diff');
			exec('diff -ruN --exclude=".svn" diffstat/typo3_src-'.$version.' work/ > diffstat/diff-tmp.diff');

			$this->writeMessage('Creating diffstat and saving it to diffstat/diffstat-'.$version.'-'.$this->information['versionNumber'].'.txt');
			exec('diffstat diffstat/diff-tmp.diff > diffstat/diffstat-'.$version.'-'.$this->information['versionNumber'].'.txt');
			exec('rm -f diffstat/diff-tmp.diff');
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
		$this->exec('copher/copher.pl '.$this->copherArgs.' --user='.$this->information['sf_user'].' --password='.$this->information['sf_pass'].' --release="TYPO3 '.$this->information['versionNumber'].'" --date='.strftime('%Y-%m-%d').' --notes=packaging/target/md5sum.txt --changelog=work/NEWS.txt '.$files);
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

