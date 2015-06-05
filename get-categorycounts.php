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
  'startup' => array('filter' => "EXTRACT(EPOCH FROM reports_clean.uptime) <= '60'",
                     'process_split' => true,
                     'channels' => array('release', 'beta', 'aurora', 'nightly'),
                     'products' => array('Firefox', 'FennecAndroid')),
);

// for how many days back to get the data
$backlog_days = 7;

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');

// File storing the DB access data - including password!
$fdbsecret = '/home/rkaiser/.socorro-prod-dbsecret.json';

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }

// *** code start ***

// get current day
$curtime = time();

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

      $max_build_age = getMaxBuildAge($channel, true);

      if (file_exists($fprodcatdata)) {
        print('Read stored '.$catname.' data for '.$product.' '.$channel."\n");
        $prodcatdata = json_decode(file_get_contents($fprodcatdata), true);
      }
      else {
        $prodcatdata = array();
      }

      foreach ($days_to_analyze as $anaday) {
        print('Category Counts: Looking at '.$catname.' data for '.$product.' '.$channel.' on '.$anaday."\n");

        $rep_query =
          'SELECT COUNT(*) as cnt'.($rep['process_split']?',reports_clean.process_type':'').' '
          .'FROM reports_clean'
          .' LEFT JOIN signatures'
          .' ON (reports_clean.signature_id=signatures.signature_id)'
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
            $prodcatdata[$anaday][$catname][strtolower($rep_row['process_type'])] = $rep_row['cnt'];
          }
          else {
            $prodcatdata[$anaday][$catname] = $rep_row['cnt'];
          }
        }
      }

      file_put_contents($fprodcatdata, json_encode($prodcatdata));
      print("\n");
    }
  }
}

// *** helper functions ***

?>
