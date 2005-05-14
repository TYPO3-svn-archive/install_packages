#!/usr/bin/php4 -q
<?php

function message($msg) {
	echo "\n".$msg."\n";
}

error_reporting (E_ALL ^ E_NOTICE ^ E_WARNING); 

if(isset($GLOBALS['argv'][1])) {
	$dir = $GLOBALS['argv'][1];
} else {
	die("No sitepath specified - terminating\n");
}

if (!ereg('\/$',$dir)) $dir.='/';


define("PATH_typo3", $dir.'typo3/');
define("PATH_site", dirname(PATH_typo3)."/");
define("PATH_t3lib", PATH_typo3."t3lib/");
define('TYPO3_mainDir','typo3/');
define("PATH_typo3conf", PATH_site."typo3conf/");	// Typo-configuraton path
define('TYPO3_MODE','BE');


require(PATH_t3lib."class.t3lib_div.php");
require(PATH_t3lib."class.t3lib_extmgm.php");

// create a temporary DB
$command = 'mysql -utypo3 -ptypo30 -e"CREATE DATABASE typo3_dbupdater_temp;"';
message(`$command`);

//  ...and fill it with the contents of the old database.sql
$command = 'mysql -utypo3 -ptypo30 typo3_dbupdater_temp < '.PATH_typo3conf.'database.sql';
message(`$command`);

// set Typo3 database information
$typo_db_username = 'typo3';
$typo_db_password = 'typo30';
$typo_db_host = 'localhost';
$typo_db = 'typo3_dbupdater_temp';


// saving the database information to the constants.
// config_default.php will try to overwrite those, but it can't because constants can't be overwritten
define('TYPO3_db', $typo_db);
define('TYPO3_db_username', $typo_db_username);
define('TYPO3_db_password', $typo_db_password);
define('TYPO3_db_host', $typo_db_host);

// include config file
require(PATH_t3lib."config_default.php");

if (!defined ("TYPO3_db")) 	die ("The configuration file was not included.");

// establish database connection
require(PATH_t3lib.'class.t3lib_db.php');
$TYPO3_DB = t3lib_div::makeInstance('t3lib_DB');
$result = $GLOBALS['TYPO3_DB']->sql_pconnect(TYPO3_db_host, TYPO3_db_username, TYPO3_db_password); 
if (!$result)	{
	die("Couldn't connect to database at ".TYPO3_db_host);
}

if(!$TYPO3_DB->sql_select_db('typo3_dbupdater_temp')) {
	message('Failed to select the database!');
}





// ****************************************************
// Include tables customization (tables + ext_tables)
// ****************************************************
include (TYPO3_tables_script ? PATH_typo3conf.TYPO3_tables_script : PATH_t3lib.'stddb/tables.php');
	// Extension additions
if ($TYPO3_LOADED_EXT['_CACHEFILE'])	{
	include (PATH_typo3conf.$TYPO3_LOADED_EXT['_CACHEFILE'].'_ext_tables.php');
} else {
	include (PATH_t3lib.'stddb/load_ext_tables.php');
}	
	// extScript
if (TYPO3_extTableDef_script)	{
	include (PATH_typo3conf.TYPO3_extTableDef_script);
}


// include t3lib_install
require_once(PATH_t3lib.'class.t3lib_install.php');

class SC_dbupdater extends t3lib_install {
	var $updateQueriesExecuted = 0;
	var $importQueriesExecuted = 0;

	function updateDB() {

		$tblFileContent="";
		$tblFileContent = t3lib_div::getUrl(PATH_t3lib."stddb/tables.sql");
		reset($GLOBALS["TYPO3_LOADED_EXT"]);
		while(list(,$loadedExtConf)=each($GLOBALS["TYPO3_LOADED_EXT"]))	{
			if (is_array($loadedExtConf) && $loadedExtConf["ext_tables.sql"])	{
				$tblFileContent.= chr(10).chr(10).chr(10).chr(10).t3lib_div::getUrl($loadedExtConf["ext_tables.sql"]);
			}
		}
		
		if ($tblFileContent)	{
			$fileContent = implode(
				$this->getStatementArray($tblFileContent,1,"^CREATE TABLE "),
				chr(10)
			);
			$FDfile = $this->getFieldDefinitions_sqlContent($fileContent);
			if (!count($FDfile))	{
				die ("Error: There were no 'CREATE TABLE' definitions in the provided file");
			}

			// Updating database...
			$FDdb = $this->getFieldDefinitions_database();
			$diff = $this->getDatabaseExtra($FDfile, $FDdb);
			$update_statements = $this->getUpdateSuggestions($diff);
			$diff = $this->getDatabaseExtra($FDdb, $FDfile);
			$remove_statements = $this->getUpdateSuggestions($diff,"remove");

			$doUpdate = array();

			foreach($update_statements as $collection) {
				foreach($collection as $md5 => $statement) {
					$doUpdate[$md5] = 1;
				}
			}
			foreach($remove_statements as $collection) {
				foreach($collection as $md5 => $statement) {
					$doUpdate[$md5] = 1;
				}
			}
			
			$this->updateQueriesExecuted += count($doUpdate);
			
				// Starting with TYPO3 3.7 this typo has been fixed from "preformUpdateQueries" to "performUpdateQueries"
			$this->performUpdateQueries($update_statements["add"],$doUpdate);
			$this->performUpdateQueries($update_statements["change"],$doUpdate);
			$this->performUpdateQueries($remove_statements["change"],$doUpdate);
			$this->performUpdateQueries($remove_statements["drop"],$doUpdate);
			$this->performUpdateQueries($update_statements["create_table"],$doUpdate);
			$this->performUpdateQueries($remove_statements["change_table"],$doUpdate);
			$this->performUpdateQueries($remove_statements["drop_table"],$doUpdate);

		}
	}
	
	function importStatic() {
		$tblFileContent="";
		
		foreach($GLOBALS["TYPO3_LOADED_EXT"] as $loadedExtConf)	{
			if (is_array($loadedExtConf) && $loadedExtConf["ext_tables_static+adt.sql"])	{
				$tblFileContent.= chr(10).chr(10).chr(10).chr(10).t3lib_div::getUrl($loadedExtConf["ext_tables_static+adt.sql"]);
			}
		}

		$statements = $this->getStatementArray($tblFileContent,1);

			// Updating all static tables of the database
		foreach($statements as $k => $v)	{
			$res = $GLOBALS['TYPO3_DB']->admin_query($v);
			$this->importQueriesExecuted++;
		}
	}
	
	function t3lib_install() {
		// killing constructor of parent class
	}
	
	function SC_dbupdater() {
		// killing constructor of parent class	
	}

}

$dbUpdater = t3lib_div::makeInstance("SC_dbupdater");
$dbUpdater->updateDB();

$dbUpdater->updateDB(); // Doing it again. Sometimes this is necessary.
$dbUpdater->importStatic(); // Importing static tables

message('Update queries: '.$dbUpdater->updateQueriesExecuted.' ImportStatic queries: '.$dbUpdater->importQueriesExecuted);

// having done the changes to the database, we can now dump the up-to-date database to a new database.sql file
$command = 'mysqldump -utypo3 -ptypo30 typo3_dbupdater_temp > '.PATH_typo3conf.'database.sql';
message(`$command`);

// ...and finally drop the temporary database
$command = 'mysql -utypo3 -ptypo30 -e"DROP DATABASE typo3_dbupdater_temp;"';
message(`$command`);

// Additionally, delete all temp_CACHED_* files in typo3conf/

// ToDo: Check if file exists!

$command = 'rm '.PATH_typo3conf.'temp_CACHED_*.php';
message(`$command`);

?>