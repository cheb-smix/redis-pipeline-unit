<?php

namespace websocket;

class SmixConsoleLogger
{
    private static $init = false;

    public static function progress($done, $total, $size = 30, $label = "", $refreshing = true)
    {
        static $start_time;

        if ($done > $total) return;
        if (!$refreshing && $done < $total) return;
     
        if(empty($start_time)) $start_time = time();
        $now = time();
     
        $perc = (double) ($done / $total);
     
        $bar = floor($perc * $size);
     
        $status_bar = "\r[";
        $status_bar .= str_repeat("=", $bar);
        if ($bar < $size) {
            $status_bar .= ">";
            $status_bar .= str_repeat(" ", $size - $bar);
        } else {
            $status_bar .= "=";
        }
     
        $disp = number_format($perc * 100, 0);
     
        $status_bar .= "] $disp%  $done/$total";
     
        $rate = ($now - $start_time) / $done;
        $left = $total - $done;
        $eta = round($rate * $left, 2);
     
        $elapsed = $now - $start_time;
     
        $status_bar .= " $label remaining: " . number_format($eta) . " sec.  elapsed: " . number_format($elapsed) . " sec.";
     
        echo "$status_bar  ";
     
        flush();
     
        if($done >= $total) {
            $start_time = null;
            echo "\n";
        }
    }

    public static function statistics($data = [])
    {
        if (!$data) {
            return;
        }

        foreach ($data as &$col) {
            ksort($col);
        }

        echo self::tablelized($data);
    }

    private static function multilined($data = [])
    {
        $tmp = [];
        foreach ($data as $c => $col) {
            $tmp[] = $c;
            foreach ($col as $k => $v) {
                $tmp[] = "$k = $v";
            }
        }

        return chr(27) . chr(91) . 'H' . chr(27) . chr(91) . 'J' 
        . "\e[1;36m--- WEBSOCKET DAEMON MONITORING TOOL ---\e[0m\n"
        . "\e[0;33m" . implode("\n", $tmp) . "\n\e[0m";
    }

    private static function tablelized($data = [])
    {
        $cols = count($data);
        if ($cols > 3) {
            $cols = 3;
        }
        $keyAppend = 25;
        $maxValAppend = 30;
        $maxRowsNum = 0;

        $valWidths = [];

        foreach ($data as $colname => $col) {
            if ($maxRowsNum < count($col)) {
                $maxRowsNum = count($col);
            }
            $valWidths[$colname] = 3;

            foreach ($col as $k => $v) {
                $vlen = strlen((string) $v);
                if ($vlen > $valWidths[$colname] && $vlen <= $maxValAppend) {
                    $valWidths[$colname] = $vlen;
                }
            }
        }

        $tableWidth = ($keyAppend + 6) * $cols + array_sum($valWidths) + 1;

        $header = "--- WEBSOCKET DAEMON MONITORING TOOL ---";
        $headerPrepend = (int) floor((($tableWidth - 2) / 2) + strlen($header) / 2);

        $output = chr(27) . chr(91) . 'H' . chr(27) . chr(91) . 'J';
        $output .= "|" . str_repeat("¯", $tableWidth - 2) . "|\n";
        $output .= "|\e[1;36m" . sprintf("%{$headerPrepend}s", $header) . str_repeat(" ", $tableWidth - 2 - $headerPrepend) . "\e[0m|\n";
        $output .= "|" . str_repeat("-", $tableWidth - 2) . "|\n";
        foreach ($data as $colname => $col) {
            $colAppend = $keyAppend + $valWidths[$colname] + 3;
            $output .= "| " . sprintf("%-{$colAppend}s", $colname) . " ";
        }
        $output .= "|\n";
        $output .= "|" . str_repeat("-", $tableWidth - 2) . "|\n";
        for ($i = 0; $i < $maxRowsNum; $i++) {
            foreach ($data as $colname => &$col) {
                $val = $i ? next($col) : current($col);
                $key = key($col);
                if ($key) {
                    $output .= "| \e[0;33m" . sprintf("%-{$keyAppend}s", $key) . " = " . sprintf("%-{$valWidths[$colname]}s", $val) . "\e[0m ";
                } else {
                    $output .= "| " . str_repeat(" ", $keyAppend + $valWidths[$colname] + 3) . " ";
                }
            }
            $output .= "|\n";
        }
        $output .= "|" . str_repeat("_", $tableWidth - 2) . "|\n";

        return $output;
    } 
}