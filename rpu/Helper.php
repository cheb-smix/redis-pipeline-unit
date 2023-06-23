<?php

namespace rpu;

class Helper
{
    public static function printer($s, $obj = false, $die = false)
    {
        if (!DEBUG_MESSAGES) return;

        print date("Y-m-d | H:i:s >> ");

        if ($obj) {
            print_r($s);
        } else {
            print $s;
        }
        print "\n";

        if ($die) {
            exit;
        }
    }
}