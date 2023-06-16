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

        echo "\r" . implode(";\t", array_map(function ($k, $v) {
            return "$k: $v";
        }, array_keys($data), $data));
    }
}