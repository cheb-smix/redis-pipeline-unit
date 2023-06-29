<?php

namespace rpu;

class Logger
{
    public $loggerEnabled = false;
    public $logFile;
    public $fs;

    public function __destruct()
    {
        if ($this->fs) {
            fclose($this->fs);
        }
    }

    public function exception($data)
    {
        $this->writeError($data);
        throw new \Exception($data);
    }

    public function info($data)
    {
        $this->write($data, LOGGER_INFO_LVL);
    }

    public function error($data)
    {
        $this->write($data, LOGGER_ERROR_LVL);
    }

    public function success($data)
    {
        $this->write($data, LOGGER_SUCCEEDED);
    }

    public function write($data, int $loglvl = LOGGER_INFO_LVL)
    {
        $this->prepare($data);
        $this->print($data, $loglvl);

        if (!$this->logFile) {
            $this->logFile = __DIR__ . "/../log/rpu.log." . date("d.m.Y") . ".log"; 
        }
        
        if (LOGGER_MODE < $loglvl) {
            return;
        }
        
        if (!$this->fs) {
            $this->fs = fopen($this->logFile, "a+");
        }

        if ($this->fs) {
            fwrite($this->fs, $data);
        }
    }

    public function prepare(&$data)
    {
        $data = date("Y-m-d | H:i:s >> ") . (in_array(gettype($data), ["integer", "string", "double"]) ? $data : print_r($data, true));
    }

    public function print(string $string, int $loglvl = LOGGER_INFO_LVL)
    {
        if (DEBUG_MESSAGES) {
            if ($loglvl == LOGGER_SUCCEEDED) {
                $style = "\e[92m";
            } elseif ($loglvl == LOGGER_ERROR_LVL) {
                $style = "\e[91m";
            } else {
                $style = "\e[37m";
            }
            print "$style$string\e[0m\n";
        }
    }

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

        foreach ($data["data"] as &$col) {
            ksort($col);
        }

        echo self::tablelized($data["data"], $data["warning"]);
    }

    private static function multilined($data = [], $bottomWarning = "")
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

    private static function tablelized($data = [], $bottomWarning = "")
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
        
        if ($bottomWarning) {
            $output .= "\n\e[101m" . $bottomWarning . "\e[0m\n";
        }

        return $output;
    } 
}