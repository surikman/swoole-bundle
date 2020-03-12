<?php

declare(strict_types=1);

namespace K911\Swoole\Server;

use Assert\Assertion;
use Assert\AssertionFailedException;
use K911\Swoole\Server\Config\Socket;
use K911\Swoole\Server\Config\Sockets;
use K911\Swoole\Server\Config\WorkerEstimator;
use function array_key_exists;
use function array_keys;
use function sprintf;
use function swoole_cpu_num;

/**
 * @todo Create interface and split this class
 * @final
 */
class HttpServerConfiguration
{
    private const SWOOLE_HTTP_SERVER_CONFIG_DAEMONIZE = 'daemonize';
    private const SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC = 'serve_static';
    private const SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT = 'reactor_count';
    private const SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT = 'worker_count';
    private const SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT = 'task_worker_count';
    private const SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR = 'public_dir';
    private const SWOOLE_HTTP_SERVER_CONFIG_LOG_FILE = 'log_file';
    private const SWOOLE_HTTP_SERVER_CONFIG_LOG_LEVEL = 'log_level';
    private const SWOOLE_HTTP_SERVER_CONFIG_PID_FILE = 'pid_file';
    private const SWOOLE_HTTP_SERVER_CONFIG_BUFFER_OUTPUT_SIZE = 'buffer_output_size';
    private const SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST = 'max_request';
    private const SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST_GRACE = 'max_request_grace';

    /**
     * @todo add more
     *
     * @see https://github.com/swoole/swoole-docs/blob/master/modules/swoole-server/configuration.md
     * @see https://github.com/swoole/swoole-docs/blob/master/modules/swoole-http-server/configuration.md
     * @const array<string, string>
     */
    private const SWOOLE_HTTP_SERVER_CONFIGURATION = [
        self::SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT => 'reactor_num',
        self::SWOOLE_HTTP_SERVER_CONFIG_DAEMONIZE => 'daemonize',
        self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT => 'worker_num',
        self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC => 'enable_static_handler',
        self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR => 'document_root',
        self::SWOOLE_HTTP_SERVER_CONFIG_LOG_FILE => 'log_file',
        self::SWOOLE_HTTP_SERVER_CONFIG_LOG_LEVEL => 'log_level',
        self::SWOOLE_HTTP_SERVER_CONFIG_PID_FILE => 'pid_file',
        self::SWOOLE_HTTP_SERVER_CONFIG_BUFFER_OUTPUT_SIZE => 'buffer_output_size',
        self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT => 'task_worker_num',
        self::SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST => 'max_request',
        self::SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST_GRACE => 'max_request_grace',
    ];

    /**
     * @const array<string, bool>
     */
    private const SWOOLE_SERVE_STATIC = [
        'off' => false,
        'advanced' => false,
        'default' => true,
    ];

    /**
     * @const array<string, int>
     */
    private const SWOOLE_LOG_LEVELS = [
        'debug' => SWOOLE_LOG_DEBUG,
        'trace' => SWOOLE_LOG_TRACE,
        'info' => SWOOLE_LOG_INFO,
        'notice' => SWOOLE_LOG_NOTICE,
        'warning' => SWOOLE_LOG_WARNING,
        'error' => SWOOLE_LOG_ERROR,
    ];

    /**
     * @var WorkerEstimator
     */
    private $workerEstimator;

    /**
     * @var Sockets Sockets
     */
    private $sockets;

    /**
     * @var string
     */
    private $runningMode;

    /**
     * @var array
     */
    private $settings;

    /**
     * @param WorkerEstimator $workerEstimator
     * @param Sockets $sockets
     * @param string  $runningMode
     * @param array   $settings    settings available:
     *                             - reactor_count (default: number of cpu cores)
     *                             - worker_count (default: 2 * number of cpu cores)
     *                             - task_worker_count (default: unset; "auto" => number of cpu cores; number of task workers)
     *                             - serve_static_files (default: false)
     *                             - public_dir (default: '%kernel.root_dir%/public')
     *                             - buffer_output_size (default: '2097152' unit in byte (2MB))
     *
     * @throws AssertionFailedException
     */
    public function __construct(
        WorkerEstimator $workerEstimator,
        Sockets $sockets,
        string $runningMode = 'process',
        array $settings = []
    ) {
        $this->workerEstimator = $workerEstimator;
        $this->sockets = $sockets;

        $this->changeRunningMode($runningMode);
        $this->initializeSettings($settings);
    }

    public function changeRunningMode(string $runningMode): void
    {
        Assertion::inArray($runningMode, ['process', 'reactor', 'thread']);

        $this->runningMode = $runningMode;
    }

    public function isDaemon(): bool
    {
        return isset($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_DAEMONIZE]);
    }

    public function hasPidFile(): bool
    {
        return isset($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_PID_FILE]);
    }

    public function servingStaticContent(): bool
    {
        return isset($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC]) && 'off' !== $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC];
    }

    public function hasPublicDir(): bool
    {
        return isset($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR]);
    }

    /**
     * @param Socket $socket
     */
    public function changeServerSocket(Socket $socket): void
    {
        $this->sockets->changeServerSocket($socket);
    }

    public function getSockets(): Sockets
    {
        return $this->sockets;
    }

    /**
     * @param string $publicDir
     *
     * @throws AssertionFailedException
     */
    public function enableServingStaticFiles(string $publicDir): void
    {
        $settings = [
            self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR => $publicDir,
        ];

        if ('off' === $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC]) {
            $settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC] = 'default';
        }

        $this->setSettings($settings);
    }

    public function isReactorRunningMode(): bool
    {
        return 'reactor' === $this->runningMode;
    }

    public function getRunningMode(): string
    {
        return $this->runningMode;
    }

    /**
     * @throws AssertionFailedException
     *
     * @return int
     */
    public function getPid(): int
    {
        Assertion::true($this->existsPidFile(), 'Could not get pid file. It does not exists or server is not running in background.');

        /** @var string $contents */
        $contents = file_get_contents($this->getPidFile());
        Assertion::numeric($contents, 'Contents in pid file is not an integer or it is empty');

        return (int) $contents;
    }

    public function existsPidFile(): bool
    {
        return $this->hasPidFile() && file_exists($this->getPidFile());
    }

    /**
     * @throws AssertionFailedException
     *
     * @return string
     */
    public function getPidFile(): string
    {
        Assertion::keyIsset($this->settings, self::SWOOLE_HTTP_SERVER_CONFIG_PID_FILE, 'Setting "%s" is not set.');

        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_PID_FILE];
    }

    public function getWorkerCount(): int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT];
    }

    public function getReactorCount(): int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT];
    }

    public function hasMaxRequest(): bool
    {
        return isset($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST]);
    }

    public function getMaxRequest(): int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST];
    }

    public function hasMaxRequestGrace(): bool
    {
        return isset($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST_GRACE]);
    }

    public function getMaxRequestGrace(): int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST_GRACE];
    }

    public function getServerSocket(): Socket
    {
        return $this->sockets->getServerSocket();
    }

    /**
     * @throws AssertionFailedException
     *
     * @return string|null
     */
    public function getPublicDir(): string
    {
        Assertion::true($this->hasPublicDir(), sprintf('Setting "%s" is not set or empty.', self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR));

        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR];
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * Get settings formatted for swoole http server.
     *
     * @return array
     *
     * @see \Swoole\Http\Server::set()
     *
     * @todo create swoole settings transformer
     */
    public function getSwooleSettings(): array
    {
        $swooleSettings = [];
        foreach ($this->settings as $key => $setting) {
            $swooleSettingKey = self::SWOOLE_HTTP_SERVER_CONFIGURATION[$key];
            $swooleGetter = sprintf('getSwoole%s', str_replace('_', '', $swooleSettingKey));
            if (method_exists($this, $swooleGetter)) {
                $setting = $this->{$swooleGetter}();
            }

            if (null !== $setting) {
                $swooleSettings[$swooleSettingKey] = $setting;
            }
        }

        return $swooleSettings;
    }

    /**
     * @return int
     *
     * @see getSwooleSettings()
     */
    public function getSwooleLogLevel(): int
    {
        return self::SWOOLE_LOG_LEVELS[$this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_LOG_LEVEL]];
    }

    /**
     * @return bool
     *
     * @see getSwooleSettings()
     */
    public function getSwooleEnableStaticHandler(): bool
    {
        return self::SWOOLE_SERVE_STATIC[$this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC]];
    }

    public function getSwooleDocumentRoot(): ?string
    {
        return 'default' === $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC] ? $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR] : null;
    }

    /**
     * @param string|null $pidFile
     *
     * @throws AssertionFailedException
     */
    public function daemonize(?string $pidFile = null): void
    {
        $settings = [self::SWOOLE_HTTP_SERVER_CONFIG_DAEMONIZE => true];

        if (null !== $pidFile) {
            $settings[self::SWOOLE_HTTP_SERVER_CONFIG_PID_FILE] = $pidFile;
        }

        $this->setSettings($settings);
    }

    public function getTaskWorkerCount(): int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT] ?? 0;
    }

    /**
     * @param array $init
     *
     * @throws AssertionFailedException
     */
    private function initializeSettings(array $init): void
    {
        $this->settings = [];
        $cpuCores = swoole_cpu_num();

        if (!isset($init[self::SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT])) {
            $init[self::SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT] = $cpuCores;
        }

        if (!isset($init[self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT])) {
            $init[self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT] = 2 * $cpuCores;
        }

        if (array_key_exists(self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT, $init) && 'auto' === $init[self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT]) {
            $init[self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT] = $cpuCores;
        }

        $this->setSettings($init);
    }

    /**
     * @param array $settings
     *
     * @throws AssertionFailedException
     */
    private function setSettings(array $settings): void
    {
        foreach ($settings as $name => $value) {
            if (null !== $value) {
                $value = $this->filterSetting($name, $value);
                $this->validateSetting($name, $value);
                $this->settings[$name] = $value;
            }
        }

        Assertion::false($this->isDaemon() && !$this->hasPidFile(), 'Pid file is required when using daemon mode');
        Assertion::false($this->servingStaticContent() && !$this->hasPublicDir(), 'Enabling static files serving requires providing "public_dir" setting.');
    }

    /**
     * @param string $key
     * @param        $value
     *
     * @return mixed
     */
    private function filterSetting(string $key, $value)
    {
        switch ($key) {
            case self::SWOOLE_HTTP_SERVER_CONFIG_DAEMONIZE:
                return (bool) $value;
                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_BUFFER_OUTPUT_SIZE:
            case self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT:
            case self::SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT:
            case self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT:
            case self::SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST:
            case self::SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST_GRACE:
                return (int) $value;
                break;
            default:
                return $value;
        }
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @throws AssertionFailedException
     */
    private function validateSetting(string $key, $value): void
    {
        Assertion::keyExists(self::SWOOLE_HTTP_SERVER_CONFIGURATION, $key, 'There is no configuration mapping for setting "%s".');

        switch ($key) {
            case self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC:
                Assertion::inArray($value, array_keys(self::SWOOLE_SERVE_STATIC));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_DAEMONIZE:
                Assertion::boolean($value);

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR:
                Assertion::directory($value, 'Public directory does not exists. Tried "%s".');

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_LOG_LEVEL:
                Assertion::inArray($value, array_keys(self::SWOOLE_LOG_LEVELS));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_BUFFER_OUTPUT_SIZE:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::greaterThan($value, 0, 'Buffer output size value cannot be negative or zero, "%s" provided.');

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT:
            case self::SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT:
            case self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT:
            case self::SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST:
            case self::SWOOLE_HTTP_SERVER_CONFIG_MAX_REQUEST_GRACE:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::greaterThan($value, 0, 'Count value cannot be negative, "%s" provided.');

                break;
            default:
                return;
        }
    }
}
