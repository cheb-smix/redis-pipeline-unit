<?php

namespace rpu;

class App
{
    private $config = [];
    private $classes = [];
    private $argv = [];

    public function __construct($argv)
    {
        $this->autoload(["./websocket", "./rpu"]);

        if (file_exists(__DIR__ . "/../config/main-local.php")) {
            $this->config = array_merge(
                require(__DIR__ . "/../config/main.php"),
                require(__DIR__ . "/../config/main-local.php")
            );
        } else {
            $this->config = require(__DIR__ . "/../config/main.php");
        }

        $this->argv = $argv;
    }

    public function run()
    {
        if (\in_array("server", $this->argv)) {

            $client = new WSServer($this->config);

        } elseif (\in_array("monitor", $this->argv)) {

            $client = new WSMonitor($this->config);

        } elseif (\in_array("client", $this->argv)) {

            $client = new WSClient($this->config);

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