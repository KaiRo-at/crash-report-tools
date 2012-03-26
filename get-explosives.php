#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script retrieves explosiveness stats for crash signatures.

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

// reports to gather. fields:
//   product - product name
//   version - empty is all versions
//   throttlestart - throttle start date
//   fake_adu - no ADUs known, fake the value
//   mincount - minimum count of crashes per day to analyze

$reports = array(array('product'=>'Firefox',
                       'version'=>'',
                       'throttlestart'=>null,
                       'fake_adu'=>false,
                       'mincount'=>100,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'14',
                       'version_regex'=>'14\..*',
                       'throttlestart'=>null,
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'13',
                       'version_regex'=>'13\..*',
                       'throttlestart'=>null,
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'12',
                       'version_regex'=>'12\..*',
                       'throttlestart'=>null,
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'11',
                       'version_regex'=>'11\..*',
                       'throttlestart'=>strtotime('2012-03-19'), // >20%
                       'fake_adu'=>false,
                       'mincount'=>70,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'10',
                       'version_regex'=>'10\..*',
                       'throttlestart'=>strtotime('2012-02-04'), // >20%
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'3.6',
                       'version_regex'=>'3\.6\..*',
                       'throttlestart'=>null,
                       'fake_adu'=>false,
                       'mincount'=>50,
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'aurora',
                       'throttlestart'=>null,
                       'fake_adu'=>true,
                       'mincount'=>10,
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'nightly',
                       'throttlestart'=>null,
                       'fake_adu'=>true,
                       'mincount'=>10,
                      ),
                 array('product'=>'Fennec',
                       'version'=>'10',
                       'version_regex'=>'10\..*',
                       'throttlestart'=>null,
                       'fake_adu'=>true,
                       'mincount'=>10,
                      ),
                 array('product'=>'Fennec',
                       'version'=>'11',
                       'version_regex'=>'11\..*',
                       'throttlestart'=>null,
                       'fake_adu'=>true,
                       'mincount'=>10,
                      ),
                 array('product'=>'Fennec',
                       'version'=>'12',
                       'version_regex'=>'12\..*',
                       'throttlestart'=>null,
                       'fake_adu'=>true,
                       'mincount'=>10,
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'aurora',
                       'throttlestart'=>null,
                       'fake_adu'=>true,
                       'mincount'=>6,
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'nightly',
                       'throttlestart'=>null,
                       'fake_adu'=>true,
                       'mincount'=>6,
                      ),
                 array('product'=>'FennecAndroid',
                       'version'=>'12',
                       'version_regex'=>'12\..*',
                       'throttlestart'=>null,
                       'fake_adu'=>true,
                       'mincount'=>10,
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'aurora',
                       'throttlestart'=>null,
                       'fake_adu'=>true,
                       'mincount'=>6,
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'nightly',
                       'throttlestart'=>null,
                       'fake_adu'=>true,
                       'mincount'=>6,
                      ),
                );

// for how many days back to get the data
$backlog_days = 20;

// *** explosiveness tuning ***

$exp_vars = array(
  // minimum of crashes/ADU to use as "dist" values
  'clamp_1' => 30,
  'clamp_3' => 15,
  // limit for explosiveness output to trigger warning
  'limit_1' => 2,
  'limit_3' => 2,
);

// *** URLs and paths ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
$url_adu['firefox'] = 'https://metrics.mozilla.com/stats/firefox_updates.csv';
$url_csvbase = $on_moz_server?'/mnt/crashanalysis/crash_analysis/'
                             :'http://people.mozilla.com/crash_analysis/';
$url_algolink = 'https://wiki.mozilla.org/CrashKill/Plan/Explosive';
$url_siglinkbase = 'https://crash-stats.mozilla.com/report/list?signature=';
$url_nullsiglink = 'https://crash-stats.mozilla.com/report/list?missing_sig=EMPTY_STRING';

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }


// *** code start ***

// get current day
$curtime = time();

foreach ($reports as $rep) {
  $channel = array_key_exists('channel', $rep)?$rep['channel']:'';
  $ver = array_key_exists('version', $rep)?$rep['version']:'';
  $prd = strtolower($rep['product']);
  $prdvershort = (($prd == 'firefox')?'ff':(($prd == 'fennec')?'fn':(($prd == 'fennecandroid')?'fna':$prd)))
                 .(strlen($channel)?'-'.$channel:'')
                 .(strlen($ver)?'-'.$ver:'');
  $prdverfile = $prd
                .(strlen($channel)?'.'.$channel:'')
                .(strlen($ver)?'.'.$ver:'');
  $prdverdisplay = $rep['product']
                   .(strlen($channel)?' '.ucfirst($channel):'')
                   .(strlen($ver)?' '.(isset($rep['version_display'])?$rep['version_display']:$ver):'');

  $crdata = array();
  $adu = array();
  $total = array();
  $t_factor = array();

  $adufile = $prd.'-adu.'.date('Y-m-d', $curtime).'.csv';
  if (!$rep['fake_adu']) {
    if (isset($url_adu[$prd]) && !file_exists($adufile)) {
      print('Fetching today\'s '.$rep['product'].' ADU data from the web'."\n");
      foreach (glob($prd.'-adu.*.csv') as $filename) { unlink($filename); }
      // fails due to a PHP error
      //copy($url_ff_adu, $adufile);
      // workaround:
      shell_exec('wget '.$url_adu[$prd].' -O '.$adufile);
    }
    if (!file_exists($adufile)) { break; }
  }

  for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
    $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
    $anadir = date('Y-m-d', $anatime);
    print('Looking at data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $fcsv = date('Ymd', $anatime).'-pub-crashdata.csv';
    $fsigs = $prdvershort.'-sigs.csv';
    $fsigcnt = $prdvershort.'-sigcount.csv';
    $ftotal = $prdvershort.'-total.csv';
    $fcrcnt = $prdvershort.'-crashcount.csv';
    $fadu = $prdvershort.'-adu.csv';
    $fexpdata = $prdvershort.'-expdata.json';
    $fweb = $anadir.'.'.$prdverfile.'.explosiveness.html';

    $t_factor[$anadir] = (!is_null($rep['throttlestart']) &&
                          ($rep['throttlestart'] <= $anatime)) ? 10 : 1;

    // make sure we have the crashdata csv
    if ($on_moz_server) {
      $anafcsvgz = $url_csvbase.date('Ymd', $anatime).'/'.$fcsv.'.gz';
      if (!file_exists($anafcsvgz)) { break; }
    }
    else {
      $anafcsv = $anadir.'/'.$fcsv;
      if (!file_exists($anafcsv)) {
        print('Fetching '.$anafcsv.' from the web'."\n");
        $webcsvgz = $url_csvbase.date('Ymd', $anatime).'/'.$fcsv.'.gz';
        if (copy($webcsvgz, $anafcsv.'.gz')) { shell_exec('gzip -d '.$anafcsv.'.gz'); }
      }
      if (!file_exists($anafcsv)) { break; }
    }

    // get signature list for the product
    $anafsigs = $anadir.'/'.$fsigs;
    if (!file_exists($anafsigs)) {
      print('Getting all '.$prdverdisplay.' signatures'."\n");
      // simplified from http://people.mozilla.org/~chofmann/crash-stats/top-crash+also-found-in40.sh
      // some parts from that split into total and crashcount blocks, though
      // $7 is product, $8 is version, $28 is duplicate_of, $29 is release_channel
      $cmd = 'awk \'-F\t\' \'$7 ~ /^'.$rep['product'].'$/'
             .(strlen($channel)?' && $29 ~ /^'.awk_quote($channel, '/').'$/':'')
             .(strlen($ver)?' && $8 ~ /^'.(isset($rep['version_regex'])?$rep['version_regex']:awk_quote($ver, '/')).'$/':'')
//             .' && $28 != "\\\\N"'
             .' {printf "\t%s\n",$1}\'';
      if ($on_moz_server) {
        shell_exec('gunzip --stdout '.$anafcsvgz.' | '.$cmd.' > '.$anafsigs);
      }
      else {
        shell_exec($cmd.' '.$anafcsv.' > '.$anafsigs);
      }
    }

    // get total crash count
    $anaftotal = $anadir.'/'.$ftotal;
    if (!file_exists($anaftotal)) {
      print('Getting total crash count'."\n");
      shell_exec('cat '.$anafsigs.' | wc -l > '.$anaftotal);
    }
    $total[$anadir] = intval(file_get_contents($anaftotal));

    // get signature count *note: not actually used in here, but needed elsewhere*
    $anafsigcnt = $anadir.'/'.$fsigcnt;
    if (!file_exists($anafsigcnt)) {
      print('Getting signature count'."\n");
      shell_exec('cat '.$anafsigs.' | sort | uniq | wc -l > '.$anafsigcnt);
    }

    // get topcrasher list with counts per signature
    $anafcrcnt = $anadir.'/'.$fcrcnt;
    if (!file_exists($anafcrcnt)) {
      print('Getting a list of counted signatures'."\n");
      $cmd = 'cat '.$anafsigs.' | sort | uniq -c | sort -nr > '.$anafcrcnt;
      shell_exec($cmd);
    }

    // fetch ADU
    $anafadu = $anadir.'/'.$fadu;
    if (file_exists($adufile) && (!file_exists($anafadu) || !filesize($anafadu)) && !strlen($channel)) {
      print('Getting ADU for this day'."\n");
      // fetch total ADU for that day from metrics data
      $cmd = 'awk \'-F,\' \'$1 ~ /^date$/\' '.$adufile;
      $csvidx = explode(',', shell_exec($cmd));
      $fld_num = strlen($ver) ? array_search($ver, $csvidx) + 1 : 2;
      if ($fld_num > 1) {
        $cmd = 'awk \'-F,\' \'$1 ~ /^'.$anadir.'$/ {print $'.$fld_num.'}\' '
                   .$adufile.' > '.$anafadu;
        shell_exec($cmd);
      }
    }
    $adu[$anadir] = file_exists($anafadu) ? intval(file_get_contents($anafadu)) : 0;
    if (!$adu[$anadir] && $rep['fake_adu']) { $adu[$anadir] = 1000000; }

    // get explosiveness
    if ($adu[$anadir] && $total[$anadir] && ($daysback < $backlog_days - 8)) {
      $anafexpdata = $anadir.'/'.$fexpdata;
      if (!file_exists($anafexpdata)) {
        // get topcrasher list with counts per signature
        print('Calculate explosiveness'."\n");

        $exp = array();
        $dayset = array($anadir);
        $aduset = array($adu[$anadir]);
        $totalset = array($t_factor[$anadir] *
                          $total[$anadir] / $adu[$anadir]);
        for ($i = 1; $i < 11; $i++) {
          $prevdir = date('Y-m-d',
                          strtotime(date('Y-m-d', $anatime).' -'.$i.' day'));
          $dayset[] = $prevdir;
          $aduset[] = $adu[$prevdir];
          $totalset[] = $adu[$prevdir] ?
                        $t_factor[$prevdir] * $total[$prevdir] / $adu[$prevdir] :
                        0;
        }
        $exp_total = get_explosiveness($totalset, $aduset, $exp_vars);
        $exp_total['dataset'] = $totalset;

        // loop over signatures over a certain threshold
        $cmd = 'awk \'-F\t\' \'$1 > '.$rep['mincount'].'\' '.$anafcrcnt;
        $anacrashes = explode("\n", shell_exec($cmd));
        $tcrank = 1;
        foreach ($anacrashes as $anacrash) {
          if (preg_match('/^\s*(\d+)\s+(.*)$/', $anacrash, $regs)) {
            $sig = $regs[2];
            $sigidx = md5($sig);
            $crdata[$sigidx][$anadir] = $regs[1];
            print(':');
            $dataset = array($t_factor[$anadir] *
                             $crdata[$sigidx][$anadir] / $adu[$anadir]);
            $rawcountset = array($crdata[$sigidx][$anadir]);
            // get crash counts for previous days if not yet in the array
            for ($i = 1; $i < 11; $i++) {
              if (!array_key_exists($dayset[$i], $crdata[$sigidx])) {
                $cmd = 'awk \'-F\t\' \'$2 ~ /^'.awk_quote($sig, '/')
                       .'$/ {print $1}\' '.$dayset[$i].'/'.$fcrcnt;
                $crdata[$sigidx][$dayset[$i]] = intval(shell_exec($cmd));
                // the block below is DEBUG code only
                //if ((strpos($sig, 'ProcessDisplayItems') !== false) ||
                //    (!$crdata[$sigidx][$dayset[$i]] && $i ==1)) {
                //  print("\n".$cmd."\n");
                //}
                print($crdata[$sigidx][$dayset[$i]]?'+':'-');
              }
              else { print('.'); }
              $dataset[] = $adu[$dayset[$i]] ?
                           $t_factor[$dayset[$i]] *
                             $crdata[$sigidx][$dayset[$i]] / $adu[$dayset[$i]] :
                           0;
              $rawcountset[] = $crdata[$sigidx][$dayset[$i]];
            }

            // Now that we have the data, calculate explosiveness!
            $exp[$sigidx] = get_explosiveness($dataset, $aduset, $exp_vars);
            $exp[$sigidx]['sig'] = $sig;
            $exp[$sigidx]['dataset'] = $dataset;
            $exp[$sigidx]['rawcountset'] = $rawcountset;
            $exp[$sigidx]['tcrank'] = $tcrank++;
            print('.');
          }
        }
        print("\n");

        uasort($exp, 'expmax_compare'); // sort by max, highest-first

        file_put_contents($anafexpdata,
                          json_encode(array('exp'=>$exp,
                                            'exp_total'=> $exp_total,
                                            'dayset'=> $dayset)));
      }
      else {
        print('Read stored explosiveness'."\n");
        $edata = json_decode(file_get_contents($anafexpdata), true);
        $exp = $edata['exp'];
        $exp_total = $edata['exp_total'];
        $dayset = $edata['dayset'];
      }

      $anafweb = $anadir.'/'.$fweb;
      if (!file_exists($anafweb)) {
        // create out an HTML page
        print('Write HTML output'."\n");
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true; // we want a nice output

        $root = $doc->appendChild($doc->createElement('html'));
        $head = $root->appendChild($doc->createElement('head'));
        $title = $head->appendChild($doc->createElement('title',
            $anadir.' '.$prdverdisplay.' Explosiveness Report'));

        $body = $root->appendChild($doc->createElement('body'));
        $h1 = $body->appendChild($doc->createElement('h1',
            $anadir.' '.$prdverdisplay.' Explosiveness Report'));

        // description
        $para = $body->appendChild($doc->createElement('p',
            'All signatures with more than '.$rep['mincount']
            .' crashes on '.$prdverdisplay.'*,'
            .' ordered by explosiveness (max of the two values),'
            .' with topcrash rank noted.'));
        $para->appendChild($doc->createElement('br'));
        $para->appendChild($doc->createTextNode('See the '));
        $link = $para->appendChild($doc->createElement('a',
            'explosiveness wiki page'));
        $link->setAttribute('href', $url_algolink);
        $para->appendChild($doc->createTextNode(
            ' for more information on the algorithm used.'));

        $table = $body->appendChild($doc->createElement('table'));
        $table->setAttribute('border', '1');

        // table head
        $tr = $table->appendChild($doc->createElement('tr'));
        $th = $tr->appendChild($doc->createElement('th', 'TC #'));
        $th->setAttribute('title', 'top-crash rank');
        $th->setAttribute('rowspan', '2');
        $th = $tr->appendChild($doc->createElement('th', 'Signature'));
        $th->setAttribute('rowspan', '2');
        $th = $tr->appendChild($doc->createElement('th', 'Explosiveness'));
        $th->setAttribute('colspan', '2');
        $th = $tr->appendChild($doc->createElement('th',
            'Data (total crashes'.($rep['fake_adu']?'':' / 1M ADU').')'));
        $th->setAttribute('colspan', '11');
        $tr = $table->appendChild($doc->createElement('tr'));
        $th = $tr->appendChild($doc->createElement('th', '1-day'));
        $th = $tr->appendChild($doc->createElement('th', '3-day'));
        for ($i = 0; $i < 11; $i++) {
          $th = $tr->appendChild($doc->createElement('th', $dayset[$i]));
          $th->setAttribute('style', 'font-size: small;');
        }

        // total crashes row
        $tr = $table->appendChild($doc->createElement('tr'));
        $td = $tr->appendChild($doc->createElement('td', 'Total crashes'));
        $td->setAttribute('colspan', '2');
        if ($exp_total['warn_1'] || $exp_total['warn_3']) {
          $td->setAttribute('style', 'font-weight: bold;');
        }
        $td = $tr->appendChild($doc->createElement('td',
            sprintf('%.1f', $exp_total['explosiveness_1'])));
        $td->setAttribute('align', 'right');
        if ($exp_total['warn_1']) {
          $td->setAttribute('style', 'font-weight: bold; color: red;');
        }
        $td = $tr->appendChild($doc->createElement('td',
            sprintf('%.1f', $exp_total['explosiveness_3'])));
        $td->setAttribute('align', 'right');
        if ($exp_total['warn_3']) {
          $td->setAttribute('style', 'font-weight: bold; color: red;');
        }
        foreach ($exp_total['dataset'] as $i=>$c_per_adu) {
          $td = $tr->appendChild($doc->createElement('td',
              sprintf($rep['fake_adu']?'%d':'%.3f', $c_per_adu * pow(10, 6))));
          $td->setAttribute('align', 'right');
          if (!$rep['fake_adu']) {
            $td->appendChild($doc->createElement('br'));
            $td->appendChild($doc->createElement('small',
                '('.round($total[$dayset[$i]]/1000).'k/'
                .round($adu[$dayset[$i]]/pow(10,6)).'M)'));
          }
        }

        // signatures rows
        foreach ($exp as $expdata) {
          $tr = $table->appendChild($doc->createElement('tr'));
          $td = $tr->appendChild($doc->createElement('td', $expdata['tcrank']));
          $td->setAttribute('align', 'right');
          $td = $tr->appendChild($doc->createElement('td'));
          $style = 'font-size: small;';
          if ($expdata['warn_1'] || $expdata['warn_3']) {
            $style .= 'font-weight: bold;';
          }
          $td->setAttribute('style', $style);
          if (!strlen($expdata['sig'])) {
            $link = $td->appendChild($doc->createElement('a', '(empty signature)'));
            $link->setAttribute('href', $url_nullsiglink);
          }
          elseif ($expdata['sig'] == '\N') {
            $td->appendChild($doc->createTextNode('(processing failure - "'.$expdata['sig'].'")'));
          }
          else {
            // common case, useful signature
            $link = $td->appendChild($doc->createElement('a', htmlentities($expdata['sig'])));
            $link->setAttribute('href', $url_siglinkbase.rawurlencode($expdata['sig']));
          }
          $td = $tr->appendChild($doc->createElement('td',
              sprintf('%.1f', $expdata['explosiveness_1'])));
          $td->setAttribute('align', 'right');
          if ($expdata['warn_1']) {
            $td->setAttribute('style', 'font-weight: bold; color: red;');
          }
          $td = $tr->appendChild($doc->createElement('td',
              sprintf('%.1f', $expdata['explosiveness_3'])));
          $td->setAttribute('align', 'right');
          if ($expdata['warn_3']) {
            $td->setAttribute('style', 'font-weight: bold; color: red;');
          }
          foreach ($expdata['dataset'] as $idx=>$c_per_adu) {
            $td = $tr->appendChild($doc->createElement('td',
                sprintf($rep['fake_adu']?'%d':'%.3f', $c_per_adu * pow(10,6))));
            $td->setAttribute('align', 'right');
            $td->setAttribute('title', $expdata['rawcountset'][$idx].' crashes');
          }
        }

        $doc->saveHTMLFile($anafweb);
      }
    }
    print("\n");
  }
}

// *** helper functions ***

// Function to calculate explosiveness values
function get_explosiveness($dataset, $aduset, $exp_vars) {
  $exp_out = array();
  $baseset_1 = array_slice($dataset, 1, 10);
  $avgADU_1 = max(arr_mean(array_slice($aduset, 1, 10)), 1);
  $base_1 = arr_mean($baseset_1);
  // maximum of the clamp/ADU and the distance of the max value to the mean
  $dist_1 = max($exp_vars['clamp_1'] / $avgADU_1, max($baseset_1) - $base_1);
  $data_1 = $dataset[0];
  $exp_out['explosiveness_1'] = ($data_1 - $base_1) / $dist_1;
  $exp_out['warn_1'] = ($exp_out['explosiveness_1'] > $exp_vars['limit_1']);

  $baseset_3 = array_slice($dataset, 3, 10);
  $avgADU_3 = max(arr_mean(array_slice($aduset, 3, 10)), 1);
  $base_3 = arr_mean($baseset_3);
  // maximum of the clamp/ADU and the standard deviation of the mean
  $dist_3 = max($exp_vars['clamp_3'] / $avgADU_3, arr_stddev($baseset_3, $base_3));
  $data_3 = arr_mean(array_slice($dataset, 0, 3));
  $exp_out['explosiveness_3'] = ($data_3 - $base_3) / $dist_3;
  $exp_out['warn_3'] = ($exp_out['explosiveness_3'] > $exp_vars['limit_3']);

  $exp_out['exp_max'] = max($exp_out['explosiveness_1'],
                            $exp_out['explosiveness_3']);
  return $exp_out;
}

// Comparison function using ex_max member (reverse sort!)
function expmax_compare($a, $b) {
  if ($a['exp_max'] == $b['exp_max']) { return 0; }
  return ($a['exp_max'] > $b['exp_max']) ? -1 : 1;
}

?>
