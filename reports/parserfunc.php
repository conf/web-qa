<?php
#  +----------------------------------------------------------------------+
#  | PHP QA Website                                                       |
#  +----------------------------------------------------------------------+
#  | Copyright (c) 1997-2011 The PHP Group                                |
#  +----------------------------------------------------------------------+
#  | This source file is subject to version 3.01 of the PHP license,      |
#  | that is bundled with this package in the file LICENSE, and is        |
#  | available through the world-wide-web at the following url:           |
#  | http://www.php.net/license/3_01.txt                                  |
#  | If you did not receive a copy of the PHP license and are unable to   |
#  | obtain it through the world-wide-web, please send a note to          |
#  | license@php.net so we can mail you a copy immediately.               |
#  +----------------------------------------------------------------------+
#  | Author: Olivier Doucet <odoucet@php.net>                             |
#  +----------------------------------------------------------------------+
#   $Id$

/**
 * Insert PHP make test results in SQLite database
 *
 * The following structure must be used as first array : 
 *  [status]    => enum(failed, success)
 *  [version]   => string   - example: 5.4.1-dev
 *  [userEmail] => mangled
 *  [date]      => unix timestamp
 *  [phpinfo]   => string  - phpinfo() output (CLI)
 *  [buildEnvironment] => build environment
 *  [failedTest] => array: list of failed test. Example: array('/Zend/tests/declare_001.phpt')
 *  [expectedFailedTest] => array of expected failed test (same format as failedTest)
 *  [succeededTest] => array of successfull tests. Provided only when parsing ci.qa results (for now)
 *  [tests] => array
        testName => array (
            'output' => string("Current output of test")
            'diff'   => string("Diff with expected output of this test")
 * @param array array to insert
 * @param array releases we accept (so that we don't accept a report that claims to be PHP 8.1 for example)
 */
function insertToDb_phpmaketest($array, $QA_RELEASES = array()) 
{
    if (!is_array($array)) {
        // impossible to fetch data. We'll record this error later ...
        
    } else {
        if (strtolower($array['status']) == 'failed') 
            $array['status'] = 0;
            
        elseif (strtolower($array['status']) == 'success') 
            $array['status'] = 1;
            
        else 
            die('status unknown: '.$array['status']);
            
        if (!is_valid_php_version($array['version'], $QA_RELEASES)) {
            exit('invalid version');
        }
        
        $dbFile = dirname(__FILE__).'/db/'.$array['version'].'.sqlite';
        
        $queriesCreate = array (
            'failed' => 'CREATE TABLE IF NOT EXISTS failed (
                  `id` integer PRIMARY KEY AUTOINCREMENT,
                  `id_report` bigint(20) NOT NULL,
                  `test_name` varchar(128) NOT NULL,
                  `output` STRING NOT NULL,
                  `diff` STRING NOT NULL,
                  `signature` binary(16) NOT NULL
                )',
            'expectedfail' => 'CREATE TABLE IF NOT EXISTS expectedfail (
                  `id` integer PRIMARY KEY AUTOINCREMENT,
                  `id_report` bigint(20) NOT NULL,
                  `test_name` varchar(128) NOT NULL,
                  `output` STRING NOT NULL,
                  `diff` STRING NOT NULL,
                  `signature` binary(16) NOT NULL
                )',
            'success' => 'CREATE TABLE IF NOT EXISTS success (
                  `id` integer PRIMARY KEY AUTOINCREMENT,
                  `id_report` bigint(20) NOT NULL,
                  `test_name` varchar(128) NOT NULL
                )',
            'reports' => 'CREATE TABLE IF NOT EXISTS reports (
                  id integer primary key AUTOINCREMENT,
                  date datetime NOT NULL,
                  status smallint(1) not null,
                  nb_failed unsigned int(10)  NOT NULL,
                  nb_expected_fail unsigned int(10)  NOT NULL,
                  success unsigned int(10) NOT NULL,
                  build_env STRING NOT NULL,
                  phpinfo STRING NOT NULL,
                  user_email varchar(64) default null
            )',
        );
        
        
        if (!file_exists($dbFile)) {
            //Create DB
            $dbi = new SQLite3($dbFile, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            foreach ($queriesCreate as $table => $query) {
                $dbi->exec($query);
                if ($dbi->lastErrorCode() != '') {
                    echo "ERROR when creating table ".$table.": ".$dbi->lastErrorMsg()."\n";
                    exit;
                }
            }
            $dbi->close();
        }
        $dbi = new SQLite3($dbFile, SQLITE3_OPEN_READWRITE) or exit('cannot open DB to record results');
        
        // add expectedfail / success table if not exists
        $dbi->exec($queriesCreate['expectedfail']);
        $dbi->exec($queriesCreate['success']);
        
        // patch add field success
        @$dbi->exec('ALTER TABLE reports ADD COLUMN success unsigned int(10) NOT NULL');
        
		// handle tests with no success
		if (!isset($array['succeededTest'])) $array['succeededTest'] = array();
        
        $query = "INSERT INTO `reports` (`id`, `date`, `status`, 
        `nb_failed`, `nb_expected_fail`, `success`, `build_env`, `phpinfo`, user_email) VALUES    (null, 
        datetime(".((int) $array['date']).", 'unixepoch', 'localtime'), 
        ".((int)$array['status']).", 
        ".count($array['failedTest']).", 
        ".count($array['expectedFailedTest']).", 
		".count($array['succeededTest']).", 
        ('".$dbi->escapeString($array['buildEnvironment'])."'), 
        ('".$dbi->escapeString($array['phpinfo'])."'),
        ".(!$array['userEmail'] ? "NULL" : "'".$dbi->escapeString($array['userEmail'])."'")."
        )";
        
        $dbi->query($query);
        if ($dbi->lastErrorCode() != '') {
            echo "ERROR: ".$dbi->error."\n";
            exit;
        }

        $reportId = $dbi->lastInsertRowID();

        foreach ($array['failedTest'] as $name) {
            $test = $array['tests'][$name];
            $query = "INSERT INTO `failed` 
            (`id`, `id_report`, `test_name`, signature, `output`, `diff`) VALUES    (null, 
            '".$reportId."', '".$name."', 
            X'".md5($name.'__'.$test['diff'])."',
            ('".$dbi->escapeString($test['output'])."'), ('".$dbi->escapeString($test['diff'])."'))";
            
            @$dbi->query($query);
            if ($dbi->lastErrorCode() != '') {
                echo "ERROR when inserting failed test : ".$dbi->error."\n";
                exit;
            } 
        }
        
        foreach ($array['expectedFailedTest'] as $name) {
            $test = $array['tests'][$name];
            $query = "INSERT INTO `expectedfail` 
            (`id`, `id_report`, `test_name`, signature, `output`, `diff`) VALUES    (null, 
            '".$reportId."', '".$name."', 
            X'".md5($name.'__'.$test['diff'])."',
            ('".$dbi->escapeString($test['output'])."'), ('".$dbi->escapeString($test['diff'])."'))";
            
            @$dbi->query($query);
            if ($dbi->lastErrorCode() != '') {
                echo "ERROR when inserting expected fail test : ".$dbi->error."\n";
                exit;
            } 
        }
        
        foreach ($array['succeededTest'] as $name) {
            $test = $array['tests'][$name];
            $query = "INSERT INTO `success` 
            (`id`, `id_report`, `test_name`) VALUES (null, 
            '".$reportId."', '".$name."')";
            
            @$dbi->query($query);
            if ($dbi->lastErrorCode() != '') {
                echo "ERROR when inserting succeeded test : ".$dbi->error."\n";
                exit;
            } 
        }
        $dbi->close();
        
        // remove cache
        if (file_exists($dbFile.'.cache'))
            unlink($dbFile.'.cache');
    }
    return true;
}

function parse_phpmaketest($version, $status, $file)
{
    $extract = array();

    $extract['version'] = $version;
    $extract['status']  = $status;
    $extract['userEmail'] = null;

    $extract['date'] = time();

    $extract['expectedFailedTest'] = array();
    $extract['failedTest'] = array();
    $extract['outputsRaw'] = array();
    $extract['tests']      = array();
    $extract['phpinfo']    = '';
    $extract['buildEnvironment']    = '';

    //for each part
    $rows = explode("\n", $file);
    $currentPart = '';
    $currentTest = '';

    foreach ($rows as $row) {
        if (preg_match('@^={5,}@', $row) && $currentPart != 'phpinfo' && $currentPart != 'buildEnvironment') {
            // =======
            $currentPart = '';
            
        } elseif ($currentPart == '' && trim($row) == 'FAILED TEST SUMMARY') {
            $currentPart = 'failedTest';    
            
        } elseif ($currentPart == '' && trim($row) == 'EXPECTED FAILED TEST SUMMARY') {
            $currentPart = 'expectedFailedTest';
            
        } elseif ($currentPart == '' && trim($row) == 'BUILD ENVIRONMENT') {
            $currentPart = 'buildEnvironment';
            $currentTest = '';
            
        } elseif (trim($row) == 'PHPINFO') {
            $currentPart = 'phpinfo';
            $currentTest = '';
            
        } elseif ($currentPart == 'failedTest' || $currentPart == 'expectedFailedTest') {
            preg_match('@ \[([^\]]{1,})\]@', $row, $tab);
            if (count($tab) == 2)
                if (!isset($extract[$currentPart])  || !in_array($tab[1], $extract[$currentPart])) 
                    $extract[$currentPart][] = $tab[1];
                    
        } elseif ($currentPart == 'buildEnvironment') {
            if (preg_match('@User\'s E-mail: (.*)$@', $row, $tab)) {
                //User's E-mail
                $extract['userEmail'] = trim($tab[1]);
            }
            if (!isset($extract[$currentPart]))
                $extract[$currentPart] = '';
            $extract[$currentPart] .= $row."\n";
            
        } elseif ($currentPart == 'phpinfo') {
            if (!isset($extract[$currentPart]))
                $extract[$currentPart] = '';
            $extract[$currentPart] .= $row."\n";
            
        } elseif (substr(trim($row), -5) == '.phpt') {
            $currentTest = trim($row);
            continue;
        }
        if ($currentPart == '' && $currentTest != '') {
            if (!isset($extract['outputsRaw'][$currentTest])) 
                $extract['outputsRaw'][$currentTest] = '';
            $extract['outputsRaw'][$currentTest] .= $row."\n";
            
        }
    }
    // 2nd try to cleanup name
    $prefix = '';


    foreach ($extract['outputsRaw'] as $name => $output) {
        if (strpos($name, '/ext/') !== false) {
            $prefix = substr($name, 0, strpos($name, '/ext/'));
            break;
        }
        if (strpos($name, '/Zend/') !== false) {
            $prefix = substr($name, 0, strpos($name, '/Zend/'));
            break;
        }
    }

    if ($prefix == '' && count($extract['outputsRaw']) > 0) {
        return 'cannot determine prefix (last test name: '.$name.')';
    }


    // 2nd loop on outputs
    foreach ($extract['outputsRaw'] as $name => $output) {
        $name = substr($name, strlen($prefix));
        $extract['tests'][$name] = array ('output' => '', 'diff' => '');
        $outputTest = '';
        $diff = '';
        $startDiff = false;
        $output = explode("\n", $output);
        
        foreach ($output as $row) {
            if (preg_match('@^={5,}(\s)?$@', $row)) {
                if ($outputTest != '') $startDiff = true;
                
            } elseif ($startDiff === false) {
                $outputTest .= $row."\n";
                
            } elseif (preg_match('@^[0-9]{1,}@', $row)) {
                $diff .= $row."\n";
            }
        }
        $extract['tests'][$name]['output'] = $outputTest;
        $extract['tests'][$name]['diff']   = rtrim(
            preg_replace('@ [^\s]{1,}'.substr($name, 0, -1).'@', ' %s/'.basename(substr($name, 0, -1)), $diff)
        );
    }
    unset($extract['outputsRaw']);

    // cleanup phpInfo
    $extract['phpinfo'] = preg_replace('@^={1,}\s+@', '', $extract['phpinfo']);
    $extract['buildEnvironment'] = trim(preg_replace('@^={1,}\s+@', '', $extract['buildEnvironment']));
    $extract['buildEnvironment'] = preg_replace('@={1,}$@', '', trim($extract['buildEnvironment']));

    return $extract;
}
