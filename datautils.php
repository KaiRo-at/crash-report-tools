<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script contains utility functions used by multiple other scripts.


// Function to safely escape variables handed to awk
function awk_quote($string) {
  return strtr(preg_replace("/([\]\[^$.*?+{}\/\\\\()|])/", '\\\\$1', $string),
               array('`'=>'\140',"'"=>'\047'));
}

// Function to sanitize names to be used in IDs, etc.
function sanitize_name($string, $maxlength = 0) {
  $conv_name = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $string));
  $newname = ''; $i = 0;
  while (($i < strlen($conv_name)) && (!$maxlength || (strlen($newname) < $maxlength))) {
    if (((ord($conv_name{$i}) >= 48) && (ord($conv_name{$i}) <= 57)) ||
        ((ord($conv_name{$i}) >= 97) && (ord($conv_name{$i}) <= 122))) {
      $newname .= $conv_name{$i};
    }
    elseif (strlen($newname) && ($newname{strlen($newname)-1} != '_')) {
      $newname .= '_';
    }
    $i++;
  }
  $newname = trim($newname, '_');
  return $newname;
}

// Function to calculate square of value - mean
function arr_mean($array) { return array_sum($array) / count($array); }

// Function to calculate square of value - mean
function dist_square($x, $mean) { return pow($x - $mean, 2); }

// Function to calculate standard deviation (uses sist_square)
function arr_stddev($array, $mean = null) {
  // square root of sum of squares devided by N-1
  if (is_null($mean)) { $mean = arr_mean($array); }
  return sqrt(array_sum(array_map('dist_square', $array,
                                  array_fill(0, count($array), $mean))) /
              (count($array) - 1));
}

// Function to format a numerical value with units
function formatValue($aValue, $aPrecision, $aUnit) {
  $formatted = '';
  if ($aUnit == 'kMG') {
    $val = $aValue;
    $prec = $aPrecision;
    $unit = '';
    if ($aValue > 1e10) {
      $prec = ($prec === null) ? 0 : $prec;
      $val = round($val / 1e9, $prec);
      $unit = 'G';
    }
    elseif ($aValue > 1e9) {
      $prec = ($prec === null) ? 1 : $prec;
      $val = round($val / 1e9, $prec);
      $unit = 'G';
    }
    elseif ($aValue > 1e7) {
      $prec = ($prec === null) ? 0 : $prec;
      $val = round($val / 1e6, $prec);
      $unit = 'M';
    }
    elseif ($aValue > 1e6) {
      $prec = ($prec === null) ? 1 : $prec;
      $val = round($val / 1e6, $prec);
      $unit = 'M';
    }
    elseif ($aValue > 1e4) {
      $prec = ($prec === null) ? 0 : $prec;
      $val = round($val / 1e3, $prec);
      $unit = 'k';
    }
    elseif ($aValue > 1e3) {
      $prec = ($prec === null) ? 1 : $prec;
      $val = round($val / 1e3, $prec);
      $unit = 'k';
    }
    elseif ($prec !== null) {
      $val = round($val, $prec);
    }
    $formatted = $val.$unit;
  }
  else {
    $formatted = round($aValue, $aPrecision);
    if ($aUnit)
      $formatted .= $aUnit;
  }
  return $formatted;
}

function getMaxBuildAge($channel, $version_overall = false) {
  switch ($channel) {
    case 'release':
      return '12 weeks';
    case 'beta':
      return '4 weeks';
    case 'aurora':
      return $version_overall?'9 weeks':'2 weeks';
    case 'nightly':
      return $version_overall?'9 weeks':'1 weeks';
  }
  return '1 year'; // almost forever
}

function getDataPath() {
  $data_path = null;
  foreach (array('/mnt/crashanalysis/rkaiser/',
                 '/home/rkaiser/reports/',
                 '/mnt/mozilla/projects/socorro/')
           as $testpath) {
    if (file_exists($testpath) && is_dir($testpath)) {
      $data_path = $testpath;
    }
  }
  return $data_path;
}

function getDBConnection($fdbsecret) {
  if (file_exists($fdbsecret)) {
    $dbsecret = json_decode(file_get_contents($fdbsecret), true);
    if (!is_array($dbsecret) || !count($dbsecret)) {
      print('ERROR: No DB secrets found, aborting!'."\n");
      exit(1);
    }
    $db_conn = pg_pconnect('host='.$dbsecret['host']
                          .' port='.$dbsecret['port']
                          .' dbname=breakpad'
                          .' user='.$dbsecret['user']
                          .' password='.$dbsecret['password']);
    if (!$db_conn) {
      print('ERROR: DB connection failed, aborting!'."\n");
      exit(1);
    }
    // For info on what data can be accessed, see also
    // http://socorro.readthedocs.org/en/latest/databasetabledesc.html
    // For the DB schema, see
    // https://github.com/mozilla/socorro/blob/master/sql/schema.sql
  }
  else {
    // Won't work! (Set just for documenting what fields are in the file.)
    $dbsecret = array('host' => 'host.m.c', 'port' => '6432',
                      'user' => 'analyst', 'password' => 'foo');
    print('ERROR: No DB secrets found, aborting!'."\n");
    exit(1);
  }
  return $db_conn;
}

?>
