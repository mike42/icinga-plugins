#!/usr/bin/env php
<?php
/* Plugin to check printer status via SNMP

Copyright (C) 2013 Michael Billington <michael.billington@gmail.com>

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE. */

/* Get options */
$shortopts = "c:w:C:H:hvV";
$longopts = array("version", "verbose", "help", "community:", "warning:", "critical:", "hostname:", "consumable:", "status");
$options = getopt($shortopts, $longopts);
$usage = $argv[0] . " -H hostname [-C community -w warning -c critical] [--consumable 1..n | --status]\n";

$community = isset($options['community'])? $options['community'] : (isset($options['C'])? $options['C'] : 'public');
$hostname  = isset($options['hostname'])? $options['hostname'] : (isset($options['H'])? $options['H'] : '');
$warning   = isset($options['warning'])? $options['warning'] : (isset($options['w'])? $options['w'] : 0);
$critical  = isset($options['critical'])? $options['critical'] : (isset($options['c'])? $options['c'] : 0);

/* Determine what to process */
if(isset($options['status']) || !isset($options['consumable'])) {
    $checkStatus = true;
    $consumable_num = 0;
} else {
    $checkStatus = false;
    $consumable_num = (int)$options['consumable'];
}

/* Catch a bunch of bad options here */
if($hostname == "" || $warning < 0 || $warning > 100 ||
    $critical < 0 || (!$checkStatus && $consumable_num < 1) || $critical > 100
    || $warning < $critical || isset($options['help'])) {
    echo $usage;   
    exit(3);
}

if($checkStatus) {
    $regex = "^iso.3.6.1.2.1.43.16.5.1.2.1.1 ";
    $base = "iso.3.6.1.2.1.43.16.5.1.2.1.1";
    $ret = walk($hostname, $community, $regex, $base);
    if(count($ret) == 1) {
        $status = array_shift($ret);
        if($status != "") {
            /* Print back the status */
            echo "$status\n";
            exit(0);
        }
        echo "Empty status string\n";
        exit(0);
    } else {
        echo "Printer did not report a status.\n";
        exit(3);
    }
} else {
    // Check consumable
    $regex = "^iso.3.6.1.2.1.43.11.1.1.[689].1.".(int)$consumable_num." ";
    $base = "iso.3.6.1.2.1.43.11.1.1";
    $ret = walk($hostname, $community, $regex, $base);
    if(count($ret) == 3) {
        $name = array_shift($ret);
        $total = array_shift($ret);
        $amount = array_shift($ret);
        $percentage = (int)(($amount / $total) * 100);
        if($percentage <= $critical) {
            echo "$name CRITICAL : $percentage%\n";
            exit(2);
        } elseif($percentage <= $warning) {
            echo "$name WARNING : $percentage%\n";
            exit(1);
        } else {
            echo "$name OK : $percentage%\n";
            exit(0);
        }
    } else {
        echo "Printer did not report the level for this consumable.\n";
        exit(3);
    }
}

/* snmpwalk and capture the values */
function walk($host, $community, $regex, $base) {
    $cmd_base = "snmpwalk %s -c %s -v 1 %s | grep %s";
    $cmd = sprintf($cmd_base, escapeshellarg($host), escapeshellarg($community), escapeshellarg($base), escapeshellarg($regex));
    exec($cmd, $lines);
    $ret = array();

    foreach($lines as $line) {
        /* Locate the key */
        $i = strpos($line, "=");
        $key = trim(substr($line, 0, $i));
        $line = trim(substr($line, $i + 1, strlen($line) - ($i + 1)));

        /* Locate the data type */
        $i = strpos($line, ":");
        $dt = trim(substr($line, 0, $i));
        $val = trim(substr($line, $i + 1, strlen($line) - ($i + 1)), " \"");

        $ret[$key] = $val;
    }

    ksort($ret);
    return $ret;
}
