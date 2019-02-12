<?php
/**
 * UserFrosting (http://www.userfrosting.com)
 *
 * @link      https://github.com/userfrosting/UserFrosting
 * @copyright Copyright (c) 2019 Alexander Weissman
 * @license   https://github.com/userfrosting/UserFrosting/blob/master/LICENSE.md (MIT License)
 */

namespace UserFrosting\Sprinkle\Core\ServicesProvider;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\FileSessionHandler;
use Interop\Container\ContainerInterface;
use League\FactoryMuffin\FactoryMuffin;
use League\FactoryMuffin\Faker\Facade as Faker;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use UserFrosting\Cache\TaggableFileStore;
use UserFrosting\Cache\MemcachedStore;
use UserFrosting\Cache\RedisStore;
use UserFrosting\Config\ConfigPathBuilder;
use UserFrosting\Session\Session;
use UserFrosting\Sprinkle\Core\Error\ExceptionHandlerManager;
use UserFrosting\Sprinkle\Core\Error\Handler\NotFoundExceptionHandler;
use UserFrosting\Sprinkle\Core\Log\MixedFormatter;
use UserFrosting\Sprinkle\Core\Mail\Mailer;
use UserFrosting\Sprinkle\Core\Alert\CacheAlertStream;
use UserFrosting\Sprinkle\Core\Alert\SessionAlertStream;
use UserFrosting\Sprinkle\Core\Database\Migrator\Migrator;
use UserFrosting\Sprinkle\Core\Database\Migrator\MigrationLocator;
use UserFrosting\Sprinkle\Core\Database\Migrator\DatabaseMigrationRepository;
use UserFrosting\Sprinkle\Core\Database\Seeder\Seeder;
use UserFrosting\Sprinkle\Core\Filesystem\FilesystemManager;
use UserFrosting\Sprinkle\Core\Session\NullSessionHandler;
use UserFrosting\Sprinkle\Core\Throttle\Throttler;
use UserFrosting\Sprinkle\Core\Throttle\ThrottleRule;
use UserFrosting\Sprinkle\Core\Util\CheckEnvironment;
use UserFrosting\Sprinkle\Core\Util\ClassMapper;
use UserFrosting\Support\Exception\BadRequestException;
use UserFrosting\Support\Exception\NotFoundException;
use UserFrosting\Support\Repository\Loader\ArrayFileLoader;
use UserFrosting\Support\Repository\Repository;

/**
 * UserFrosting core services provider.
 *
 * Registers core services for UserFrosting, such as config, database, asset manager, translator, etc.
 * @author Alex Weissman (https://alexanderweissman.com)
 */
class ServicesProvider
{
    /**
     * Register UserFrosting's core services.
     *
     * @param ContainerInterface $container A DI container implementing ArrayAccess and container-interop.
     */
    public function register(ContainerInterface $container)
    {
        /**
         * Flash messaging service.
         *
         * Persists error/success messages between requests in the session.
         *
         * @throws \Exception                                    If alert storage handler is not supported
         * @return \UserFrosting\Sprinkle\Core\Alert\AlertStream
         */
        $container['alerts'] = function ($c) {
            $config = $c->config;

            if ($config['alert.storage'] == 'cache') {
                return new CacheAlertStream($config['alert.key'], $c->translator, $c->cache, $c->config);
            } elseif ($config['alert.storage'] == 'session') {
                return new SessionAlertStream($config['alert.key'], $c->translator, $c->session);
            } else {
                throw new \Exception("Bad alert storage handler type '{$config['alert.storage']}' specified in configuration file.");
            }
        };

        /**
         * Cache service.
         *
         * @throws \Exception                   If cache handler is not supported
         * @return \Illuminate\Cache\Repository
         */
        $container['cache'] = function ($c) {
            $config = $c->config;

            if ($config['cache.driver'] == 'file') {
                $path = $c->locator->findResource('cache://', true, true);
                $cacheStore = new TaggableFileStore($path);
            } elseif ($config['cache.driver'] == 'memcached') {
                // We need to inject the prefix in the memcached config
                $config = array_merge($config['cache.memcached'], ['prefix' => $config['cache.prefix']]);
                $cacheStore = new MemcachedStore($config);
            } elseif ($config['cache.driver'] == 'redis') {
                // We need to inject the prefix in the redis config
                $config = array_merge($config['cache.redis'], ['prefix' => $config['cache.prefix']]);
                $cacheStore = new RedisStore($config);
            } else {
                throw new \Exception("Bad cache store type '{$config['cache.driver']}' specified in configuration file.");
            }

            return $cacheStore->instance();
        };

        /**
         * Middleware to check environment.
         *
         * @todo We should cache the results of this, the first time that it succeeds.
         *
         * @return \UserFrosting\Sprinkle\Core\Util\CheckEnvironment
         */
        $container['checkEnvironment'] = function ($c) {
            return new CheckEnvironment($c->view, $c->locator, $c->cache);
        };

        /**
         * Class mapper.
         *
         * Creates an abstraction on top of class names to allow extending them in sprinkles.
         *
         * @return \UserFrosting\Sprinkle\Core\Util\ClassMapper
         */
        $container['classMapper'] = function ($c) {
            $classMapper = new ClassMapper();
            $classMapper->setClassMapping('query_builder', 'UserFrosting\Sprinkle\Core\Database\Builder');
            $classMapper->setClassMapping('eloquent_builder', 'UserFrosting\Sprinkle\Core\Database\EloquentBuilder');
            $classMapper->setClassMapping('throttle', 'UserFrosting\Sprinkle\Core\Database\Models\Throttle');

            return $classMapper;
        };

        /**
         * Site config service (separate from Slim settings).
         *
         * Will attempt to automatically determine which config file(s) to use based on the value of the UF_MODE environment variable.
         *
         * @return \UserFrosting\Support\Repository\Repository
         */
        $container['config'] = function ($c) {
            // Grab any relevant dotenv variables from the .env file
            try {
                $dotenv = new Dotenv(\UserFrosting\APP_DIR);
                $dotenv->load();
            } catch (InvalidPathException $e) {
                // Skip loading the environment config file if it doesn't exist.
            }

            // Get configuration mode from environment
            $mode = getenv('UF_MODE') ?: '';

            // Construct and load config repository
            $builder = new ConfigPathBuilder($c->locator, 'config://');
            $loader = new ArrayFileLoader($builder->buildPaths($mode));
            $config = new Repository($loader->load());

            // Construct base url from components, if not explicitly specified
            if (!isset($config['site.uri.public'])) {
                $uri = $c->request->getUri();

                // Slim\Http\Uri likes to add trailing slashes when the path is empty, so this fixes that.
                $config['site.uri.public'] = trim($uri->getBaseUrl(), '/');
            }

            // Hacky fix to prevent sessions from being hit too much: ignore CSRF middleware for requests for raw assets ;-)
            // See https://github.com/laravel/framework/issues/8172#issuecomment-99112012 for more information on why it's bad to hit Laravel sessions multiple times in rapid succession.
            $csrfBlacklist = $config['csrf.blacklist'];
            $csrfBlacklist['^/' . $config['assets.raw.path']] = [
                'GET'
            ];

            $config->set('csrf.blacklist', $csrfBlacklist);

            return $config;
        };

        /**
         * Initialize Eloquent Capsule, which provides the database layer for UF.
         *
         * @todo construct the individual objects rather than using the facade
         * @return \Illuminate\Database\Capsule\Manager
         */
        $container['db'] = function ($c) {
            $config = $c->config;

            $capsule = new Capsule();

            foreach ($config['db'] as $name => $dbConfig) {
                $capsule->addConnection($dbConfig, $name);
            }

            $queryEventDispatcher = new Dispatcher(new Container());

            $capsule->setEventDispatcher($queryEventDispatcher);

            // Register as global connection
            $capsule->setAsGlobal();

            // Start Eloquent
            $capsule->bootEloquent();

            if ($config['debug.queries']) {
                $logger = $c->queryLogger;

                foreach ($config['db'] as $name => $dbConfig) {
                    $capsule->connection($name)->enableQueryLog();
                }

                // Register listener
                $queryEventDispatcher->listen(QueryExecuted::class, function ($query) use ($logger) {
                    $logger->debug("Query executed on database [{$query->connectionName}]:", [
                        'query'    => $query->sql,
                        'bindings' => $query->bindings,
                        'time'     => $query->time . ' ms'
                    ]);
                });
            }

            return $capsule;
        };

        /**
         * Debug logging with Monolog.
         *
         * Extend this service to push additional handlers onto the 'debug' log stack.
         *
         * @return \Monolog\Logger
         */
        $container['debugLogger'] = function ($c) {
            $logger = new Logger('debug');

            $logFile = $c->locator->findResource('log://userfrosting.log', true, true);

            $handler = new StreamHandler($logFile);

            $formatter = new MixedFormatter(null, null, true);

            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);

            return $logger;
        };

        /**
         * Custom error-handler for recoverable errors.
         *
         * @return \UserFrosting\Sprinkle\Core\Error\ExceptionHandlerManager
         */
        $container['errorHandler'] = function ($c) {
            $settings = $c->settings;

            $handler = new ExceptionHandlerManager($c, $settings['displayErrorDetails']);

            // Register the base HttpExceptionHandler.
            $handler->registerHandler('\UserFrosting\Support\Exception\HttpException', '\UserFrosting\Sprinkle\Core\Error\Handler\HttpExceptionHandler');

            // Register the NotFoundExceptionHandler.
            $handler->registerHandler('\UserFrosting\Support\Exception\NotFoundException', '\UserFrosting\Sprinkle\Core\Error\Handler\NotFoundExceptionHandler');

            // Register the PhpMailerExceptionHandler.
            $handler->registerHandler('\phpmailerException', '\UserFrosting\Sprinkle\Core\Error\Handler\PhpMailerExceptionHandler');

            return $handler;
        };

        /**
         * Error logging with Monolog.
         *
         * Extend this service to push additional handlers onto the 'error' log stack.
         *
         * @return \Monolog\Logger
         */
        $container['errorLogger'] = function ($c) {
            $log = new Logger('errors');

            $logFile = $c->locator->findResource('log://userfrosting.log', true, true);

            $handler = new StreamHandler($logFile, Logger::WARNING);

            $formatter = new LineFormatter(null, null, true);

            $handler->setFormatter($formatter);
            $log->pushHandler($handler);

            return $log;
        };

        /**
         * Factory service with FactoryMuffin.
         *
         * Provide access to factories for the rapid creation of objects for the purpose of testing
         *
         * @return \League\FactoryMuffin\FactoryMuffin
         */
        $container['factory'] = function ($c) {

            // Get the path of all of the sprinkle's factories
            $factoriesPath = $c->locator->findResources('factories://', true);

            // Create a new Factory Muffin instance
            $fm = new FactoryMuffin();

            // Load all of the model definitions
            $fm->loadFactories($factoriesPath);

            // Set the locale. Could be the config one, but for testing English should do
            Faker::setLocale('en_EN');

            return $fm;
        };

        /**
         * Filesystem Service
         * @return \UserFrosting\Sprinkle\Core\Filesystem\FilesystemManager
         */
        $container['filesystem'] = function ($c) {
            return new FilesystemManager($c->config);
        };


        /**
         * Mail service.
         *
         * @return \UserFrosting\Sprinkle\Core\Mail\Mailer
         */
        $container['mailer'] = function ($c) {
            $mailer = new Mailer($c->mailLogger, $c->config['mail']);

            // Use UF debug settings to override any service-specific log settings.
            if (!$c->config['debug.smtp']) {
                $mailer->getPhpMailer()->SMTPDebug = 0;
            }

            return $mailer;
        };

        /**
         * Mail logging service.
         *
         * PHPMailer will use this to log SMTP activity.
         * Extend this service to push additional handlers onto the 'mail' log stack.
         *
         * @return \Monolog\Logger
         */
        $container['mailLogger'] = function ($c) {
            $log = new Logger('mail');

            $logFile = $c->locator->findResource('log://userfrosting.log', true, true);

            $handler = new StreamHandler($logFile);
            $formatter = new LineFormatter(null, null, true);

            $handler->setFormatter($formatter);
            $log->pushHandler($handler);

            return $log;
        };

        /**
         * Migrator service.
         *
         * This service handles database migration operations
         *
         * @return \UserFrosting\Sprinkle\Core\Database\Migrator\Migrator
         */
        $container['migrator'] = function ($c) {
            $migrator = new Migrator(
                $c->db,
                new DatabaseMigrationRepository($c->db, $c->config['migrations.repository_table']),
                new MigrationLocator($c->locator)
            );

            // Make sure repository exist
            if (!$migrator->repositoryExists()) {
                $migrator->getRepository()->createRepository();
            }

            return $migrator;
        };


        /**
         * Error-handler for PHP runtime errors.  Notice that we just pass this through to our general-purpose
         * error-handling service.
         *
         * @return \UserFrosting\Sprinkle\Core\Error\ExceptionHandlerManager
         */
        $container['phpErrorHandler'] = function ($c) {
            return $c->errorHandler;
        };

        /**
         * Laravel query logging with Monolog.
         *
         * Extend this service to push additional handlers onto the 'query' log stack.
         *
         * @return \Monolog\Logger
         */
        $container['queryLogger'] = function ($c) {
            $logger = new Logger('query');

            $logFile = $c->locator->findResource('log://userfrosting.log', true, true);

            $handler = new StreamHandler($logFile);

            $formatter = new MixedFormatter(null, null, true);

            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);

            return $logger;
        };


        /**
         * Return an instance of the database seeder
         *
         * @return \UserFrosting\Sprinkle\Core\Database\Seeder\Seeder
         */
        $container['seeder'] = function ($c) {
            return new Seeder($c);
        };

        /**
         * Start the PHP session, with the name and parameters specified in the configuration file.
         *
         * @throws \Exception
         * @return \UserFrosting\Session\Session
         */
        $container['session'] = function ($c) {
            $config = $c->config;

            // Create appropriate handler based on config
            if ($config['session.handler'] == 'file') {
                $fs = new Filesystem();
                $handler = new FileSessionHandler($fs, $c->locator->findResource('session://'), $config['session.minutes']);
            } elseif ($config['session.handler'] == 'database') {
                $connection = $c->db->connection();
                // Table must exist, otherwise an exception will be thrown
                $handler = new DatabaseSessionHandler($connection, $config['session.database.table'], $config['session.minutes']);
            } elseif ($config['session.handler'] == 'array') {
                $handler = new NullSessionHandler();
            } else {
                throw new \Exception("Bad session handler type '{$config['session.handler']}' specified in configuration file.");
            }

            // Create, start and return a new wrapper for $_SESSION
            $session = new Session($handler, $config['session']);
            $session->start();

            return $session;
        };

        /**
         * Request throttler.
         *
         * Throttles (rate-limits) requests of a predefined type, with rules defined in site config.
         *
         * @return \UserFrosting\Sprinkle\Core\Throttle\Throttler
         */
        $container['throttler'] = function ($c) {
            $throttler = new Throttler($c->classMapper);

            $config = $c->config;

            if ($config->has('throttles') && ($config['throttles'] !== null)) {
                foreach ($config['throttles'] as $type => $rule) {
                    if ($rule) {
                        $throttleRule = new ThrottleRule($rule['method'], $rule['interval'], $rule['delays']);
                        $throttler->addThrottleRule($type, $throttleRule);
                    } else {
                        $throttler->addThrottleRule($type, null);
                    }
                }
            }

            return $throttler;
        };
    }
}
