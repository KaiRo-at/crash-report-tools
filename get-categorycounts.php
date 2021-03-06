#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script retrieves counts of crashes per day for some categories.

// *** non-commandline handling ***

if (php_sapi_name() != 'cli') {
  // not commandline, assume apache and output own source
  header('Content-Type: text/plain; charset=utf8');
  print(file_get_contents($_SERVER['SCRIPT_FILENAME']));
  exit;
}

include_once('datautils.php');

// *** script settings ***

// turn on error reporting in the script output
ini_set('display_errors', 1);

// make sure new files are set to -rw-r--r-- permissions
umask(022);

// set default time zone - right now, always the one the server is in!
date_default_timezone_set('America/Los_Angeles');


// *** deal with arguments ***
$php_self = array_shift($argv);
$force_dates = array();
if (count($argv)) {
  foreach ($argv as $date) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) &&
        date('Y-m-d', strtotime($date)) == $date) {
      $force_dates[] = $date;
    }
  }
}
if (count($force_dates)) {
  print('Forcing update for the following dates: '.implode(', ', $force_dates)."\n\n");
}

// *** data gathering variables ***

// reports to process

$reports = array(
  'startup' => array(
    'filter' => "EXTRACT(EPOCH FROM reports_clean.uptime) <= '60'",
    'process_split' => true,
    'channels' => array('release', 'beta', 'aurora', 'nightly'),
    'products' => array('Firefox', 'FennecAndroid')
  ),
  'oom' => array(
    'filter' => "(signatures.signature LIKE 'OOM |%' OR signatures.signature LIKE 'js::AutoEnterOOMUnsafeRegion::crash%')",
    'include_signature_table' => true,
    'process_split' => true,
    'channels' => array('release', 'beta', 'aurora', 'nightly'),
    'products' => array('Firefox', 'FennecAndroid')
  ),
  'oom:small' => array(
    'filter' => "signatures.signature = 'OOM | small'",
    'include_signature_table' => true,
    'process_split' => true,
    'channels' => array('release', 'beta', 'aurora', 'nightly'),
    'products' => array('Firefox', 'FennecAndroid')
  ),
  'oom:large' => array(
    'filter' => "signatures.signature LIKE 'OOM | large |%'",
    'include_signature_table' => true,
    'process_split' => true,
    'channels' => array('release', 'beta', 'aurora', 'nightly'),
    'products' => array('Firefox', 'FennecAndroid')
  ),
  'shutdownhang' => array(
    'filter' => "signatures.signature LIKE 'shutdownhang |%'",
    'include_signature_table' => true,
    'process_split' => false,
    'channels' => array('release', 'beta', 'aurora', 'nightly'),
    'products' => array('Firefox')
  ),
  'address:pure' => array(
    // The signature starts with a "pure" @0xFOOBAR address but not with a prepended "@0x0 |".
    'filter' => "signatures.signature LIKE '@0x%' AND NOT signatures.signature LIKE '@0x0 |%'",
    'include_signature_table' => true,
    'process_split' => true,
    'channels' => array('release', 'beta', 'aurora', 'nightly'),
    'products' => array('Firefox')
  ),
  'address:file' => array(
    // The signature starts with a non-symbolized file@0xFOOBAR piece (potentially after a @0x0 frame).
    'filter' => "split_part(regexp_replace(signatures.signature, '^@0x0 \| ', ''), ' | ', 1) LIKE '%_@0x%'",
    'include_signature_table' => true,
    'process_split' => true,
    'channels' => array('release', 'beta', 'aurora', 'nightly'),
    'products' => array('Firefox')
  ),
);

// for how many days back to get the data
$backlog_days = $global_defaults['backlog_days'];

// *** URLs ***

// File storing the DB access data - including password!
$fdbsecret = '/home/centos/.socorro-prod-dbsecret.json';

// *** code start ***

// get current day
$curtime = time();

$datapath = getDataPath();
if (is_null($datapath)) {
  print('ERROR: No data path found, aborting!'."\n");
  exit(1);
}
chdir($datapath);

$db_conn = getDBConnection($fdbsecret);

$days_to_analyze = array();
for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
  $days_to_analyze[] = date('Y-m-d', strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day'));
}
foreach ($force_dates as $anaday) {
  if (!in_array($anaday, $days_to_analyze)) {
    $days_to_analyze[] = $anaday;
  }
}

foreach ($reports as $catname=>$rep) {
  foreach ($rep['products'] as $product) {
    foreach ($rep['channels'] as $channel) {
      $fprodcatdata = $product.'-'.$channel.'-counts.json';

      $unthrottle_factor = ($product == 'Firefox' && $channel == 'release') ? 10 : 1;

      $max_build_age = getMaxBuildAge($channel, true);

      if (file_exists($fprodcatdata)) {
        print('Read stored '.$catname.' data for '.$product.' '.$channel."\n");
        $prodcatdata = json_decode(file_get_contents($fprodcatdata), true);
      }
      else {
        $prodcatdata = array();
      }

      foreach ($days_to_analyze as $anaday) {
        if (!array_key_exists($anaday, $prodcatdata) ||
            !array_key_exists($catname, $prodcatdata[$anaday]) ||
            in_array($anaday, $force_dates)) {
          print('Category Counts: Looking at '.$catname.' data for '.$product.' '.$channel.' on '.$anaday."\n");

          $rep_query =
            'SELECT COUNT(*) as cnt'.($rep['process_split']?',reports_clean.process_type':'').' '
            .'FROM '
            .((array_key_exists('include_signature_table', $rep) && $rep['include_signature_table'])?
               'reports_clean LEFT JOIN signatures'
               .' ON (reports_clean.signature_id=signatures.signature_id)'
              :((array_key_exists('include_reports_table', $rep) && $rep['include_reports_table'])?
                 'reports_clean LEFT JOIN reports'
                 .' ON (reports_clean.uuid=reports.uuid)'
                :'reports_clean'))
            .' LEFT JOIN product_versions'
            .' ON (reports_clean.product_version_id=product_versions.product_version_id)'
            ." WHERE product_versions.product_name = '".$product."' "
            ." AND product_versions.build_type='".$channel."'"
            ." AND product_versions.is_rapid_beta='f'"
            ." AND reports_clean.date_processed < (product_versions.build_date + interval '".$max_build_age."')"
            ." AND utc_day_is(reports_clean.date_processed, '".$anaday."')"
            .' AND '.$rep['filter']
            .($rep['process_split']?' GROUP BY reports_clean.process_type':'');

          $rep_result = pg_query($db_conn, $rep_query);
          if (!$rep_result) {
            print('--- ERROR: Report query failed!'."\n");
          }

          while ($rep_row = pg_fetch_array($rep_result)) {
            if ($rep['process_split']) {
              if (array_key_exists($anaday, $prodcatdata) &&
                  array_key_exists($catname, $prodcatdata[$anaday]) &&
                  !is_array($prodcatdata[$anaday][$catname])) {
                $prodcatdata[$anaday][$catname] = array();
              }
              $prodcatdata[$anaday][$catname][strtolower($rep_row['process_type'])] = intval($rep_row['cnt']) * $unthrottle_factor;
            }
            else {
              $prodcatdata[$anaday][$catname] = intval($rep_row['cnt']) * $unthrottle_factor;
            }
          }
        }
      }

      ksort($prodcatdata); // sort by date (key), ascending
      file_put_contents($fprodcatdata, json_encode($prodcatdata));
      print("\n");
    }
  }
}

// *** helper functions ***

?>
