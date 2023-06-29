<?php

namespace rpu;

class Helper
{
    public static $lastCPUData;
    public static $timeParts = [
        [
            "label" => "s",
            "divider" => 60,
        ], [
            "label" => "m",
            "divider" => 60,
        ], [
            "label" => "h",
            "divider" => 24,
        ], [
            "label" => "d",
            "divider" => 0,
        ],
    ];
    public static $bytesParts = [
        [
            "label" => "b",
            "divider" => 1024,
        ], [
            "label" => "kb",
            "divider" => 1024,
        ], [
            "label" => "mb",
            "divider" => 1024,
        ], [
            "label" => "gb",
            "divider" => 1024,
        ], [
            "label" => "tb",
            "divider" => 0,
        ],
    ];

    public static function getCPULoad()
    {
        $load = 0;

        if (is_readable("/proc/stat"))
        {
            $f = fopen("/proc/stat", 'r');
            $stat = preg_replace("/\s+/", " ", fgets($f));
            fclose($f);
            $stat = explode(" ", $stat);
            if (array_shift($stat) == "cpu") {
                $stat = array_slice($stat, 0, 4);
                $stat = array_map(function ($item) {
                    return (int) $item;
                }, $stat);
                $statData2 = $stat;
                if (self::$lastCPUData) {
                    $statData2[0] -= self::$lastCPUData[0];
                    $statData2[1] -= self::$lastCPUData[1];
                    $statData2[2] -= self::$lastCPUData[2];
                    $statData2[3] -= self::$lastCPUData[3];
                    $load = 100 - ($statData2[3] * 100 / array_sum($statData2));
                }
                self::$lastCPUData = $stat;
            }
        }

        return $load;
    }

    public static function format($value = 0, $type = "time", $justLast = false)
    {
        $fstr = "";
        $val = 0;

        $partsName = "{$type}Parts";

        $parts = self::$$partsName;

        foreach ($parts as $tp) {
            if ($value) {
                if ($tp['divider']) {
                    $val = $value % $tp['divider'];
                    $value = floor($value / $tp['divider']);
                } else {
                    $val = $value;
                    $value = 0;
                }
                if ($justLast) {
                    $fstr = round($val, 3) . $tp['label'];
                } else {
                    $fstr = "$val{$tp['label']} $fstr";
                }
            }
            if (!$value) {
                break;
            }
        }
    
        return $fstr;
    }

    public static function formatTime($value = 0)
    {
        return self::format($value, "time");
    }

    public static function formatBytes($value = 0)
    {
        return self::format($value, "bytes", true);
    }
}
