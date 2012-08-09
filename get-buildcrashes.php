#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script retrieves crash stats for builds.

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


// *** data gathering variables ***

// channels
$channels = array('release', 'beta', 'aurora', 'nightly', 'other');

// products
$products = array('Firefox', 'Fennec', 'FennecAndroid');

// how many days back to look at
$backlog_days = 7;

// notes for specific builds
$notes = array('Firefox-5.0-20110427143820' => '5.0b1',
               'Firefox-5.0-20110517192056' => '5.0b2',
               'Firefox-5.0-20110527093235' => '5.0b3',
               'Firefox-5.0-20110603100923' => '5.0b4',
               'Firefox-5.0-20110608151458' => '5.0b5',
               'Firefox-5.0-20110613165758' => '5.0b6',
               'Firefox-5.0-20110614174314' => '5.0b7',
               'Firefox-6.0-20110705195857' => '6.0b1',
               'Firefox-6.0-20110713171652' => '6.0b2',
               'Firefox-6.0-20110721152715' => '6.0b3',
               'Firefox-6.0-20110729080751' => '6.0b4',
               'Firefox-6.0-20110804030150' => '6.0b5',
               'Firefox-7.0-20110816154714' => '7.0b1',
               'Firefox-7.0-20110824172139' => '7.0b2',
               'Firefox-7.0-20110830100616' => '7.0b3',
               'Firefox-7.0-20110902161802' => '7.0b4',
               'Firefox-7.0-20110908135051' => '7.0b5',
               'Firefox-7.0-20110916091512' => '7.0b6',
               'Firefox-8.0-20110928060149' => '8.0b1',
               'Firefox-8.0-20111006182035' => '8.0b2',
               'Firefox-8.0-20111011182523' => '8.0b3',
               'Firefox-8.0-20111019081014' => '8.0b4',
               'Firefox-8.0-20111026191032' => '8.0b5',
               'Firefox-8.0-20111102223350' => '8.0b6',
               'Firefox-9.0-20111109112850' => '9.0b1',
               'Firefox-9.0-20111116091359' => '9.0b2',
               'Firefox-9.0-20111122192043' => '9.0b3',
               'Firefox-9.0-20111130065942' => '9.0b4',
               'Firefox-9.0-20111206234556' => '9.0b5',
               'Firefox-9.0-20111212185108' => '9.0b6',
               'Firefox-10.0-20111221135037' => '10.0b1',
               'Firefox-10.0-20111228055358' => '10.0b2',
               'Firefox-10.0-20120104111456' => '10.0b3',
               'Firefox-10.0-20120111092507' => '10.0b4',
               'Firefox-10.0-20120118081945' => '10.0b5',
               'Firefox-10.0-20120123235200' => '10.0b6',
               'Firefox-11.0-20120201153158' => '11.0b1',
               'Firefox-11.0-20120208012847' => '11.0b2',
               'Firefox-11.0-20120215222917' => '11.0b3',
               'Firefox-11.0-20120222074758' => '11.0b4',
               'Firefox-11.0-20120228210006' => '11.0b5',
               'Firefox-11.0-20120305181207' => '11.0b6',
               'Firefox-11.0-20120308162450' => '11.0b7',
               'Firefox-11.0-20120310173008' => '11.0b8',
               'Firefox-12.0-20120314195616' => '12.0b1',
               'Firefox-12.0-20120321033733' => '12.0b2',
               'Firefox-12.0-20120328051619' => '12.0b3',
               'Firefox-12.0-20120403211507' => '12.0b4',
               'Firefox-12.0-20120411064248' => '12.0b5',
               'Firefox-12.0-20120417165043' => '12.0b6',
               'Firefox-13.0-20120425123149' => '13.0b1',
               'Firefox-13.0-20120501201020' => '13.0b2',
               'Firefox-13.0-20120509070325' => '13.0b3',
               'Firefox-13.0-20120516113045' => '13.0b4',
               'Firefox-13.0-20120523114940' => '13.0b5',
               'Firefox-13.0-20120528154913' => '13.0b6',
               'Firefox-13.0-20120531155942' => '13.0b7',
               'Firefox-14.0-20120605113340' => '14.0b6',
               'Firefox-14.0-20120612164001' => '14.0b7',
               'Firefox-14.0-20120619191901' => '14.0b8',
               'Firefox-14.0-20120624012213' => '14.0b9',
               'Firefox-14.0-20120628060610' => '14.0b10',
               'Firefox-14.0-20120704090211' => '14.0b11',
               'Firefox-14.0-20120710123126' => '14.0b12',
               'Firefox-15.0-20120717110313' => '15.0b1',
               'Firefox-15.0-20120724191344' => '15.0b2',
               'Firefox-15.0-20120731150526' => '15.0b3',
               'Firefox-15.0-20120808131812' => '15.0b4',
               'Firefox-14.0.1-20120713134347' => 'official',
               'Firefox-13.0.1-20120614114901' => 'official',
               'Firefox-13.0-20120601045813' => 'official',
               'Firefox-12.0-20120420145725' => 'official',
               'Firefox-11.0-20120312181643' => 'official',
               'Firefox-10.0.5-20120531185831' => 'ESR',
               'Firefox-10.0.4-20120420145309' => 'ESR',
               'Firefox-10.0.3-20120309135702' => 'ESR',
               'Firefox-10.0.2-20120216092139' => 'ESR',
               'Firefox-10.0.2-20120215223356' => 'official',
               'Firefox-10.0.1-20120208062825' => 'ESR',
               'Firefox-10.0.1-20120208060813' => 'official',
               'Firefox-10.0-20120130064731' => 'ESR',
               'Firefox-10.0-20120129021758' => 'official',
               'Firefox-9.0.1-20111220165912' => 'official',
               'Firefox-9.0-20111216140209' => 'official',
               'Firefox-8.0.1-20111120135848' => 'official',
               'Firefox-8.0-20111104165243' => 'official',
               'Firefox-7.0.1-20110928134238' => 'official',
               'Firefox-7.0-20110922153450' => 'official',
               'Firefox-6.0.2-20110902133214' => 'official',
               'Firefox-6.0.1-20110830092941' => 'official',
               'Firefox-6.0-20110811165603' => 'official',
               'Firefox-5.0-20110615151330' => 'official',
               'Firefox-5.0.1-20110707182747' => 'official',
               'Firefox-4.0.1-20110413222027' => 'official',
               'Firefox-4.0-20110318052756' => 'official',
              );

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');

// File storing the DB access data - including password!
$fdbsecret = '/home/rkaiser/.socorro-prod-dbsecret.json';

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }

// *** code start ***

// get current day
$curtime = time();

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

$fadu = 'build-adu.json';
$adudata = file_exists($fadu)?json_decode(file_get_contents($fadu), true):array();

for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
  $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
  $anadir = date('Y-m-d', $anatime);
  print('Looking at per-build crash data for '.$anadir."\n");
  if (!file_exists($anadir)) { mkdir($anadir); }

  $fpages = 'pages.json';
  $fweb = $anadir.'.buildcrashes.html';

  $anafweb = $anadir.'/'.$fweb;
  if (!file_exists($anafweb)) {
    // create out an HTML page
    print('Write HTML output'."\n");

    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = true; // we want a nice output

    $root = $doc->appendChild($doc->createElement('html'));
    $head = $root->appendChild($doc->createElement('head'));
    $title = $head->appendChild($doc->createElement('title',
        $anadir.' Crashes / Build'));

    $body = $root->appendChild($doc->createElement('body'));
    $h1 = $body->appendChild($doc->createElement('h1',
        $anadir.' Crashes / Build'));

    // description
    $para = $body->appendChild($doc->createElement('p',
        'Total crashes seen on '.$anadir
        .' per different build '
        .'(product + version + Build ID combination).'));

    $list = $body->appendChild($doc->createElement('ul'));
    foreach ($products as $product) {
      foreach ($channels as $channel) {
        $item = $list->appendChild($doc->createElement('li'));
        $link = $item->appendChild($doc->createElement('a',
            $product.' '.ucfirst($channel)));
        $link->setAttribute('href', '#'.$product.'-'.$channel);
      }
    }

    foreach ($products as $product) {
      // get most recent major verion
      $pid_query =
        'SELECT productid '
        .'FROM product_productid_map '
        ."WHERE product_name = '".$product."';";

      $pid_result = pg_query($db_conn, $pid_query);
      if (!$pid_result) {
        print('--- ERROR: Product ID query failed!'."\n");
        break;
      }
      else {
        $pid_row = pg_fetch_array($pid_result);
        $productid = $pid_row['productid'];
      }
      $regular_pv_ids = array();
      foreach ($channels as $channel) {
        $pvdata = array();
        if ($channel != 'other') {
          $mver = array();
          // get featured major verion(s)
          $feat_query =
            'SELECT major_version '
            .'FROM product_versions '
            ."WHERE product_name = '".$product."'"
            ." AND build_type = '".ucfirst($channel)."'"
            ." AND featured_version = 't';";

          $feat_result = pg_query($db_conn, $feat_query);
          if (!$feat_result) {
            print('--- ERROR: featured query failed!'."\n");
          }
          else {
            while ($feat_row = pg_fetch_array($feat_result)) {
              $mver[] = $feat_row['major_version'];
            }
          }

          if (!count($mver)) {
            // get most recent major verion
            $last_query =
              'SELECT major_version '
              .'FROM product_versions '
              ."WHERE product_name = '".$product."'"
              ." AND build_type = '".ucfirst($channel)."' "
              ."ORDER BY build_date DESC LIMIT 1;";

            $last_result = pg_query($db_conn, $last_query);
            if (!$feat_result) {
              print('--- ERROR: Reports/signatures query failed!'."\n");
            }
            else {
              while ($last_row = pg_fetch_array($last_result)) {
                $mver[] = $last_row['major_version'];
              }
            }
          }
          if (!count($mver)) {
            print('--- ERROR: no version found for '.$product.' '.ucfirst($channel).'!'."\n");
            break;
          }

          $pv_ids = array();
          $pv_query =
            'SELECT product_version_id, release_version, version_string, build_type '
            .'FROM product_versions '
            ."WHERE product_name = '".$product."'"
            ." AND major_version IN ('".implode("','", $mver)."')"
            ." AND build_type = '".ucfirst($channel)."';";
          $pv_result = pg_query($db_conn, $pv_query);
          if (!$pv_result) {
            print('--- ERROR: product version query failed!'."\n");
          }
          else {
            while ($pv_row = pg_fetch_array($pv_result)) {
              $pv_ids[] = $pv_row['product_version_id'];
              $regular_pv_ids[] = $pv_row['product_version_id'];
              $pvdata[$pv_row['product_version_id']] = $pv_row;
            }
          }
        }
        else {
          $pv_ids = array();
          $pv_query =
            'SELECT product_version_id, release_version, version_string, build_type '
            .'FROM product_versions '
            ."WHERE product_name = '".$product."'"
            ." AND sunset_date > '".$anadir."'"
            .' AND product_version_id NOT IN ('.implode(',', $regular_pv_ids).');';
          $pv_result = pg_query($db_conn, $pv_query);
          if (!$pv_result) {
            print('--- ERROR: product version query failed!'."\n");
          }
          else {
            while ($pv_row = pg_fetch_array($pv_result)) {
              $pv_ids[] = $pv_row['product_version_id'];
              $pvdata[$pv_row['product_version_id']] = $pv_row;
            }
          }
        }

        if (!count($pv_ids)) {
          print('--- ERROR: no product versions found for '.$product.' '.ucfirst($channel).'!'."\n");
          break;
        }

        $rep_query =
          'SELECT COUNT(*) as cnt, build, product_version_id,'
          ." CASE WHEN hang_id IS NULL THEN 'crash' ELSE 'hang' END as crash_type,"
          .' process_type '
          .'FROM reports_clean '
          .'WHERE product_version_id IN ('.implode(',', $pv_ids).')'
          ." AND utc_day_is(date_processed, '".$anadir."') "
          .'GROUP BY build, product_version_id, crash_type, process_type '
          .'ORDER BY build ASC;';

        $rep_result = pg_query($db_conn, $rep_query);
        if (!$rep_result) {
          print('--- ERROR: Reports/signatures query failed!'."\n");
          break;
        }

        $listbuilds = array();
        $buildadu = array();
        $categories = array('crash'=>0, 'hang'=>0, 'browser'=>0);
        while ($rep_row = pg_fetch_array($rep_result)) {
          $idx = $rep_row['build'].'-'.$rep_row['product_version_id'];
          if (!array_key_exists($idx, $listbuilds)) {
            $listbuilds[$idx] = array('build' => $rep_row['build'],
                                      'pvid' => $rep_row['product_version_id'],
                                      'cnt' => array('total' => 0,
                                                     'norm_total' => 0));

            $adu_query =
              'SELECT SUM(adu_count) as adu '
              .'FROM raw_adu '
              ."WHERE product_guid = btrim('".$productid."', '{}')"
              ." AND build_channel = '".strtolower($pvdata[$rep_row['product_version_id']]['build_type'])."'"
              ." AND product_version = '".$pvdata[$rep_row['product_version_id']]['release_version']."'"
              ." AND build = '".$rep_row['build']."'"
              ." AND date = '".$anadir."';";

            $adu_result = pg_query($db_conn, $adu_query);
            if (!$adu_result) {
              print('--- ERROR: ADU query failed!'."\n");
            }
            else {
              $adu_row = pg_fetch_array($adu_result);
              if (intval(@$adu_row['adu'])) {
                $buildadu[$idx] = $adu_row['adu'];
              }
            }
          }
          $ptype = strtolower($rep_row['process_type']);
          if (!array_key_exists($rep_row['crash_type'], $listbuilds[$idx]['cnt'])) {
            $listbuilds[$idx]['cnt'][$rep_row['crash_type']] = 0;
          }
          $listbuilds[$idx]['cnt'][$rep_row['crash_type']] += $rep_row['cnt'];
          if (!array_key_exists($ptype, $listbuilds[$idx]['cnt'])) {
            $listbuilds[$idx]['cnt'][$ptype] = 0;
          }
          $listbuilds[$idx]['cnt'][$ptype] += $rep_row['cnt'];
          $listbuilds[$idx]['cnt']['total'] += $rep_row['cnt'];
          if ($ptype != 'browser' || $rep_row['crash_type'] != 'hang') {
            $listbuilds[$idx]['cnt']['norm_total'] += $rep_row['cnt'];
          }
          $categories[$rep_row['crash_type']] += $rep_row['cnt'];
          if (!array_key_exists($ptype, $categories)) {
            $categories[$ptype] = 0;
          }
          $categories[$ptype] += $rep_row['cnt'];
        }

        $h2 = $body->appendChild($doc->createElement('h2',
            $product.' '.ucfirst($channel)));
        $h2->setAttribute('id', $product.'-'.$channel);

        if ($channel == 'other') {
          $body->appendChild($doc->createElement('p',
              'Only known-by-Socorro builds of currently active versions are listed.'));
        }

        if (count($listbuilds)) {
          $table = $body->appendChild($doc->createElement('table'));
          $table->setAttribute('border', '1');

          // table head
          $tr = $table->appendChild($doc->createElement('tr'));
          $th = $tr->appendChild($doc->createElement('th', 'Product'));
          $th = $tr->appendChild($doc->createElement('th', 'Version'));
          $th = $tr->appendChild($doc->createElement('th', 'Build ID'));
          $th = $tr->appendChild($doc->createElement('th', 'Notes'));
          $fields = array();
          foreach ($categories as $cat=>$cnt) {
            if ($cnt) {
              $fields[] = $cat;
              $th = $tr->appendChild($doc->createElement('th', $cat));
            }
          }
          $fields[] = 'total';
          $th = $tr->appendChild($doc->createElement('th', 'total'));
          $th = $tr->appendChild($doc->createElement('th', 'normalized'));
          $th->setAttribute('title',
              'total minus half of all hangs (as hangs always come in pairs)');

          // signatures rows
          foreach ($listbuilds as $idx=>$builddata) {
            $tr = $table->appendChild($doc->createElement('tr'));
            $td = $tr->appendChild($doc->createElement('td',
                htmlentities($product, ENT_COMPAT, 'UTF-8')));
            $td = $tr->appendChild($doc->createElement('td',
                htmlentities($pvdata[$builddata['pvid']]['version_string'], ENT_COMPAT, 'UTF-8')));
            $td = $tr->appendChild($doc->createElement('td',
                htmlentities($builddata['build'], ENT_COMPAT, 'UTF-8')));
            $td = $tr->appendChild($doc->createElement('td',
                htmlentities(@$notes[$idx], ENT_COMPAT, 'UTF-8')));
            if (@$buildadu[$idx]) {
              if (@$notes[$idx]) { $td->appendChild($doc->createElement('br')); }
              $small = $td->appendChild($doc->createElement('small',
                  formatValue($buildadu[$idx], null, 'kMG').' ADU'));
              $small->setAttribute('style', 'color:GrayText;');
            }
            foreach ($fields as $fld) {
              $ptype = !in_array($fld, array('hang','crash','total'))?$fld:'any';
              $htype = in_array($fld, array('hang','crash'))?$fld:'any';

              $td = $tr->appendChild($doc->createElement('td'));
              $td->setAttribute('align', 'right');
              $link = $td->appendChild($doc->createElement('a', intval(@$builddata['cnt'][$fld])));
              $link->setAttribute('href',
                  'https://crash-stats.mozilla.com/query/query?product='.$product
                  .'&version=All'
                  .'&version='.$product.'%3A'.$pvdata[$builddata['pvid']]['version_string']
                  .'&range_value=1&range_unit=days&&date='.$anadir.'+23%3A59%3A59'
                  .'&query_type=contains&query=&reason='
                  .'&build_id='.$builddata['build']
                  .'&process_type='.$ptype.'&hang_type='.$htype
                  .'&do_query=1');
              if (@$buildadu[$idx]) {
                $td->appendChild($doc->createElement('br'));
                $small = $td->appendChild($doc->createElement('small',
                    print_rate(intval(@$builddata['cnt'][$fld]), $buildadu[$idx],
                               strtolower($pvdata[$builddata['pvid']]['build_type']),
                               $product)));
                $small->setAttribute('title', 'per 100 ADU');
                $small->setAttribute('style', 'color:GrayText;');
              }
            }
            $td = $tr->appendChild($doc->createElement('td', $builddata['cnt']['norm_total']));
            $td->setAttribute('align', 'right');
            if (@$buildadu[$idx]) {
              $td->appendChild($doc->createElement('br'));
              $small = $td->appendChild($doc->createElement('small',
                  print_rate($builddata['cnt']['norm_total'], $buildadu[$idx],
                             strtolower($pvdata[$builddata['pvid']]['build_type']),
                             $product)));
              $small->setAttribute('title', 'per 100 ADU');
              $small->setAttribute('style', 'color:GrayText;');
            }
          }
        }
        else {
          $body->appendChild($doc->createElement('p', 'No data found.'));
        }
      }
    }

    $doc->saveHTMLFile($anafweb);

    // add the page to the pages index
    $anafpages = $anadir.'/'.$fpages;
    if (file_exists($anafpages)) {
      $pages = json_decode(file_get_contents($anafpages), true);
    }
    else {
      $pages = array();
    }
    $pages[$fweb] =
      array('product' => null,
            'channel' => null,
            'version' => null,
            'report' => 'buildcrashes',
            'report_sub' => null,
            'display_ver' => '',
            'display_rep' => 'Crashes / Build');
    file_put_contents($anafpages, json_encode($pages));
  }
  print("\n");
}

// *** helper functions ***

// Function to print crash rates
function print_rate($count, $adu, $channel, $product) {
  $t_factor = ($channel == 'release' && $product == 'Firefox') ? 10 : 1;
  return sprintf('%.3f', $count * $t_factor * 100 / $adu);
}
?>
