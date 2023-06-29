<?php

namespace rpu;

class App
{
    const CLIENT_MODE = 1;
    const SERVER_MODE = 5;
    const MONITOR_MODE = 9;
    const DOS_MODE = 10;
    const HELP_MODE = 90;
    const INIT_MODE = 95;
    const UNDEFINED_MODE = 99;

    public $logger;

    private $mode = 99;
    private $config = [];
    private $classes = [];
    private $argv = [];
    private $availableArgv = [
        "-c" => "client",
        "-d" => "dos",
        "-f" => "force",
        "-h" => "help",
        "-i" => "init",
        "-k" => "key",
        "-l" => "loggerMode",
        "-m" => "monitor",
        "-p" => "port",
        "-r" => "requests",
        "-s" => "server",
        "host",
        "protocol",
    ];

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
            $argv[$i] = explode("=", $argv[$i]);
            if (count($argv[$i]) == 1) {
                $value = true;
            } else {
                $value = $argv[$i][1];
            }
            $key = $argv[$i][0];
            $this->argRebuild($key);
            if (in_array($key, $this->availableArgv)) {
                $this->argv[$key] = $value;
            }
        }

        foreach ($this->argv as $k => $v) {
            if (array_key_exists($k, $this->config)) {
                $this->config[$k] = $v;
            }
        }

        $this->mode = self::UNDEFINED_MODE;

        foreach (["client", "server", "monitor", "dos", "init", "help"] as $mode) {
            if (isset($this->argv[$mode])) {
                $this->mode = constant(self::class . "::" . \strtoupper($mode) . "_MODE");
                break;
            }
        }

        if ($this->mode === self::CLIENT_MODE) {
            define("DEBUG_MESSAGES", $this->config["client_debug_messages_on"]);
        } else {
            define("DEBUG_MESSAGES", $this->config["server_debug_messages_on"]);
        }
        define("LOGGER_MODE", $this->config['loggerMode']);

        $this->logger = new Logger();
        if ($this->mode < self::HELP_MODE) {
            $this->logger->info("Loaded classes:");
            $this->logger->info($this->classes);
            $this->logger->info("Loaded config:");
            $this->logger->info($this->config);
            $this->logger->info("Taken arguments:");
            $this->logger->info($this->argv);
        }

        $this->config["logger"] = &$this->logger;
    }

    public function run()
    {
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

        } elseif ($this->mode === self::HELP_MODE) {

            $this->logger->success("Usage: php pipe-unit [options...]", false);
            $this->logger->info("-c, --client\t\tRun WebSocket Client Example", false);
            $this->logger->info("-d, --dos\t\tRun WebSocket DOS Sequence Example [syntetic test]", false);
            $this->logger->info("-f, --force <num>\tForce rewrite local config", false);
            $this->logger->info("-h, --help\t\tHelp info", false);
            $this->logger->info("--host\t\tSpecify a host for websocket unit", false);
            $this->logger->info("-i, --init\t\tInitiate local stuff", false);
            $this->logger->info("-k, --key <string>\tSpecify a custom key name", false);
            $this->logger->info("-l, --loggerMode <num>\tSet logger mode [0 - disabled, 1 - errors, 2 - +succeeded, 3 - +info", false);
            $this->logger->info("-m, --monitor\t\tRun WebSocket Monitor", false);
            $this->logger->info("-p, --port\t\tSpecify a port for websocket unit", false);
            $this->logger->info("--protocol\t\tSpecify a scheme for websocket unit", false);
            $this->logger->info("-r, --requests <num>\tSpecify a number of request sequence", false);
            $this->logger->info("-s, --server\t\tRun WebSocket Server", false);

        } elseif ($this->mode === self::INIT_MODE) {

            $logDir = __DIR__ . "/../log";

            if (!is_dir($logDir)) {
                if(mkdir($logDir, 0770)) {
                    $this->logger->success("Log directory created");
                } else {
                    $this->logger->error("Cannot create log directory");
                }
            }

            $lclCng = __DIR__ . "/../config/main-local.php";

            if (!file_exists($lclCng) or isset($this->argv["force"])) {
                if (file_put_contents($lclCng, '<?php

return [
    "protocol"  => "tcp",
    "hostname"  => "127.0.0.1",
    "port"      => 1988,
    "pipewidth" => 1,
    "pipelineMinClients" => 3,
    "pipelineFraction" => 0.3,
    "compressEnabled" => true,
    "client_debug_messages_on" => false,
    "server_debug_messages_on" => true,
    "loggerMode" => LOGGER_ERROR_LVL,
];
                ')) {
                    $this->logger->success("Locally configurated");
                } else {
                    $this->logger->error("Local config initiation failed");
                }
            }
        } else {
            $this->logger->error("Incorrect mode. Use `help` to get info");
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

    private function argRebuild(&$k)
    {
        if (strlen($k) == 2 && isset($this->availableArgv[$k])) {
            $k = $this->availableArgv[$k];
        } else {
            trim($k, "-");
        }
    }
}