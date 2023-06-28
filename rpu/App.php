<?php

namespace rpu;

class App
{
    const CLIENT_MODE = 1;
    const SERVER_MODE = 5;
    const MONITOR_MODE = 9;
    const DOS_MODE = 10;

    private $mode;
    private $config = [];
    private $classes = [];
    private $argv = [];

    public function __construct($argv)
    {
        $this->autoload(["./websocket", "./rpu", "./redis"]);

        if (file_exists(__DIR__ . "/../config/main-local.php")) {
            $this->config = array_merge(
                require(__DIR__ . "/../config/main.php"),
                require(__DIR__ . "/../config/main-local.php")
            );
        } else {
            $this->config = require(__DIR__ . "/../config/main.php");
        }

        for ($i = 1; $i < count($argv); $i++) {
            if (stristr($argv[$i], "=")) {
                list($key, $value) = explode("=", $argv[$i]);
                $key = trim($key, "-");
                $this->argv[$key] = $value;
            } else {
                $this->argv[$argv[$i]] = true;
            }
        }

        foreach (["client", "server", "monitor", "dos"] as $mode) {
            if (isset($this->argv[$mode])) {
                $this->mode = constant(self::class . "::" . \strtoupper($mode) . "_MODE");
                break;
            }
        }
    }

    public function run()
    {
        if ($this->mode === self::CLIENT_MODE) {
            define("DEBUG_MESSAGES", $this->config["client_debug_messages_on"]);
        } else {
            define("DEBUG_MESSAGES", $this->config["server_debug_messages_on"]);
        }

        Helper::printer("Loaded classes: \n- " . implode("\n- ", $this->classes));


        if ($this->mode === self::SERVER_MODE) {

            (new WSServer())->init($this->config)->run();

        } elseif ($this->mode === self::MONITOR_MODE) {

            (new WSMonitor())->init($this->config)->run();

        } elseif ($this->mode === self::CLIENT_MODE) {

            (new WSClient())->init($this->config)->run($this->argv);

        } elseif ($this->mode === self::DOS_MODE) {

            $requests = isset($this->argv["requests"]) ? $this->argv["requests"] : 1000;
            $cnt = 0;
            for ($i = 0; $i < $requests; $i++) {
                $key = "key$i";
                if (rand(0, 10) < 3) {
                    $key = "MY_TEST_KEY" . rand(1, 20);
                }
                $cmd = "php index.php client key=$key > /dev/null 2>/dev/null &";
                `$cmd`;
            }

        }
    }

    private function autoload($folders = [])
    {
        $includeFiles = [];

        foreach ($folders as $folder) {
            $files = scandir($folder);

            foreach ($files as $file) {
                if ($file == "." || $file == "..") {
                    continue;
                }
                if (substr($file, -4) == ".php" && $file != "App.php") {
                    $this->classes[] = "$folder/$file";
                }
            }
        }

        foreach ($this->classes as $file) {
            require $file;
        }
    }
}