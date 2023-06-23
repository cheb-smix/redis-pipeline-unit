<?php

namespace rpu;

class App
{
    const CLIENT_MODE = 1;
    const SERVER_MODE = 5;
    const MONITOR_MODE = 9;

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

        foreach (["client", "server", "monitor"] as $mode) {
            if (isset($this->argv[$mode])) {
                $this->mode = constant(self::class . "::" . \strtoupper($mode) . "_MODE");
                break;
            }
        }
    }

    public function run()
    {
        if ($this->mode === self::SERVER_MODE) {

            define("DEBUG_MESSAGES", true);

            Helper::printer("Loaded classes: \n- " . implode("\n- ", $this->classes));

            (new WSServer())->init($this->config)->run();

        } elseif ($this->mode === self::MONITOR_MODE) {

            define("DEBUG_MESSAGES", true);

            (new WSMonitor())->init($this->config)->run();

        } elseif ($this->mode === self::CLIENT_MODE) {

            define("DEBUG_MESSAGES", $this->config["client_debug_messages_on"]);

            (new WSClient())->init($this->config)->run($this->argv);

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