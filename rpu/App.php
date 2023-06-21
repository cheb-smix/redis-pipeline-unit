<?php

namespace rpu;

class App
{
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

        // var_dump($this->classes);

        $this->argv = $argv;
    }

    public function run()
    {
        if (\in_array("server", $this->argv)) {

            (new WSServer())->init($this->config)->run();

        } elseif (\in_array("monitor", $this->argv)) {

            (new WSMonitor())->init($this->config)->run();

        } elseif (\in_array("client", $this->argv)) {

            (new WSClient())->init($this->config)->run();

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