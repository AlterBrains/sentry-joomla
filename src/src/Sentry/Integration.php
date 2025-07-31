<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

/** @noinspection PhpFullyQualifiedNameUsageInspection */

namespace AlterBrains\Plugin\System\Altersentry\Sentry;

use Joomla\Application\ApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\Filesystem\File;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;

\defined('_JEXEC') or die;

/**
 * @since 1.0
 */
abstract class Integration
{
    //public const INTERNAL_FRAME_FILENAME = '[internal]';
    //public const ANONYMOUS_CLASS_PREFIX = "class@anonymous\x00";

    /**
     * @var IntegrationSite
     * @since 1.0
     */
    public static $instance;

    /**
     * @since 1.0
     */
    public array $config;

    /**
     * @since 1.0
     */
    protected float $startTime;

    /**
     * @since 1.0
     */
    protected ?Transaction $transaction;

    /**
     * @since 1.0
     */
    protected static array $parentSpanStack = [], $currentSpanStack = [];

    /**
     * @since 1.0
     */
    public static function init(string $appType, array $config): ?self
    {
        $config['app_type'] = $appType;

        // Merge app-specific config
        $config = ($config['app_' . $appType] ?? []) + $config;

        /** @var self $classname */
        $classname = __CLASS__ . $appType;

        require __DIR__ . '/Integration' . \ucfirst($appType) . '.php';

        if (!$classname::isAllowed($config)) {
            return null;
        }

        require __DIR__ . '/../../vendor/autoload.php';

        return self::$instance = new $classname($config);
    }

    /**
     * Perform extra checks using config.
     * @since 1.0
     */
    public static function isAllowed(array $config): bool
    {
        return true;
    }

    /**
     * @since 1.0
     */
    public function __construct(array $config)
    {
        $this->startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? $GLOBALS['startTime'] ?? \microtime(true);

        $this->config = $config;

        // House keeping
        if (empty($this->config['tracing'])) {
            $this->config['tracing_events'] = false;
            $this->config['tracing_sql'] = false;
            $this->config['tracing_cache'] = false;
        }

        if (empty($this->config['profiling'])) {
            $this->config['sentry']['profiles_sample_rate'] = 0;
        }

        // Init log, respect possible custom 'log_path'
        if (!empty($this->config['log'])) {
            $this->config['sentry']['logger'] = new \Sentry\Logger\DebugFileLogger(
                $this->config['log_path'] ?? (($_SERVER['DOCUMENT_ROOT'] ?? '') . '/administrator/logs/sentry.log')
            );
        }

        \Sentry\init(
            $this->config['sentry'] + [
                'before_send' => [$this, 'beforeSend'],
                'before_send_transaction' => [$this, 'beforeSendTransaction'],
            ]
        );

        // Load black magic
        require_once __DIR__ . '/../Joomla/ServiceProviderInterface.php';
        require_once __DIR__ . '/../Joomla/Mailer.php';

        if ($this->config['tracing'] && $this->config['sentry']['traces_sample_rate']) {
            $this->startTransaction();
        }
    }

    /**
     * @param  string|Breadcrumb     $level      The error level of the breadcrumb, or Breadcrumb instance
     * @param  ?string               $type       The type of the breadcrumb
     * @param  ?string               $category   The category of the breadcrumb
     * @param  ?string               $message    Optional text message
     * @param  array<string, mixed>  $metadata   Additional information about the breadcrumb
     * @param  ?float                $timestamp  Optional timestamp of the breadcrumb
     * @since 1.0
     */
    public static function addBreadcrumb(
        string|Breadcrumb $level,
        string $type = null,
        string $category = null,
        ?string $message = null,
        array $metadata = [],
        ?float $timestamp = null
    ): bool {
        return SentrySdk::getCurrentHub()->addBreadcrumb(
            $level instanceof Breadcrumb
                ? $level
                : new Breadcrumb($level, $type, $category, $message, $metadata, $timestamp)
        );
    }


    public function beforeSend(\Sentry\Event $event): \Sentry\Event
    {
        return $event;
    }

    public function beforeSendTransaction(\Sentry\Event $transaction): \Sentry\Event
    {
        return $transaction;
    }

    public static function pushSpan(Span $span): void
    {
        $hub = SentrySdk::getCurrentHub();

        static::$parentSpanStack[] = $hub->getSpan();

        $hub->setSpan($span);

        static::$currentSpanStack[] = $span;
    }

    public static function popSpan(): ?Span
    {
        if (\count(static::$currentSpanStack) === 0) {
            return null;
        }

        $parent = \array_pop(static::$parentSpanStack);

        SentrySdk::getCurrentHub()->setSpan($parent);

        return \array_pop(static::$currentSpanStack);
    }

    /**
     * @since 1.0
     */
    protected function configureScope(): void
    {
        static $configured;
        if ($configured) {
            return;
        }
        $configured = true;

        if ($this->config['sentry']['release'] === 'JVERSION') {
            SentrySdk::getCurrentHub()->getClient()->getOptions()->setRelease(\JVERSION);
        }

        SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) {
            // app and it elements can be not initialized at this stage.
            try {
                $app = Factory::getApplication();
            } catch (\Throwable) {
                $app = null;
            }

            try {
                $appLang = ($app ?? null)?->getLanguage();
            } catch (\Throwable) {
            }

            try {
                $appInput = ($app ?? null)?->getInput();
            } catch (\Throwable) {
            }

            $scope->setTags([
                'app.type' => $app?->getName(),
                'app.language' => $appLang?->getTag(),
                'app.option' => $appInput?->get('option'),
                'app.view' => $appInput?->get('view'),
                'app.task' => $appInput?->get('task'),
                'app.Itemid' => $appInput?->get('Itemid'),
            ]);

            $scope->setContext('app', [
                'type' => $app?->getName(),
                'app_version' => \JVERSION,
                //'app_memory' => \memory_get_usage(true),
                //'app_start_time' => \gmdate('c', (int)$this->startTime),
            ]);
        });
    }

    /**
     * @since 1.0
     */
    protected function configureUserScope(ApplicationInterface $app): void
    {
        SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($app) {
            $userData = [
                'id' => 0,
            ];
            if ($this->config['send_user_data']) {
                try {
                    $user = $app->getIdentity();
                    if (!$user->guest && $this->config['sentry']['send_default_pii']) {
                        $userData = [
                            'id' => $user->id,
                            'username' => $user->username,
                            'name' => $user->name,
                            'email' => $user->email,
                        ];
                    }
                } catch (\Throwable) {
                }
            }

            $scope->setUser($userData);
        });
    }

    public function configureScopeBeforeSend(): void
    {
        SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) {
            if (\class_exists(SentryDispatcher::class, false)) {
                $scope->setExtra('event_stats', SentryDispatcher::$eventStats);
            }
            if (\class_exists(SentryQueryMonitor::class, false)) {
                $scope->setExtra('query_stats', SentryQueryMonitor::$queryStats);
            }
        });
    }


    /**
     * Simple version.
     * @since 1.0
     */
    public static function resolveEventOrigin(int $limit, callable|int|null $filter = null, &$frame = null): ?array
    {
        $frames = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, $limit);

        // Remove this method
        \array_shift($frames);

        if (\is_int($filter)) {
            while ($filter--) {
                \array_shift($frames);
            }
            $frame = \array_shift($frames);
        } elseif ($filter) {
            $frame = $filter($frames);
        } else {
            $frame = \array_shift($frames);
        }

        if (!$frame) {
            return null;
        }

        if (($frame['class'] ?? null) && $frame['function']) {
            $function = $frame['class'] . $frame['type'] . $frame['function'];
        } else {
            $function = $frame['function'];
        }

        // strip root path
        if (isset($frame['file']) && \str_starts_with($frame['file'], \JPATH_ROOT ?? '-')) {
            $frame['file'] = \substr($frame['file'], \strlen(\JPATH_ROOT));
        }

        return [
            // file and line can be missed if we are in shutdown handler
            'code.filepath' => $frame['file'] ?? '-',
            'code.function' => $function,
            'code.lineno' => $frame['line'] ?? '-',
        ];
    }

    public static function resolveEventOriginAsString(
        int $limit,
        callable|int|null $filter = null,
        &$frame = null
    ): ?string {
        if (null === $origin = static::resolveEventOrigin($limit, $filter, $frame)) {
            return null;
        }

        return "{$origin['code.filepath']}:{$origin['code.lineno']}";
    }

    /**
     * @since 1.0
     */
    public static function configPath(): string
    {
        return JPATH_ROOT . '/plugins/system/altersentry/config.php';
    }

    /**
     * @since 1.0
     */
    public static function writeConfig(array $config, bool $merge = true): bool
    {
        // Simple merge to preserve any custom options.
        if ($merge && ($currentConfig = static::readConfig())) {
            $config += $currentConfig;
        }

        $code = \sprintf('<?php' . "\n" . 'return %s;', \var_export($config, true));

        return File::write(static::configPath(), $code);
    }

    /**
     * @since 1.0
     */
    public static function readConfig(): ?array
    {
        $filename = static::configPath();

        return \is_file($filename) ? require $filename : null;
    }
}
