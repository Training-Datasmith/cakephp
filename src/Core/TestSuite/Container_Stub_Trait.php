<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @since         4.2.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Core\Test_Suite;

use Cake\Core\Configure;
use Cake\Core\Console_Application_Interface;
use Cake\Core\Container_Interface;
use Cake\Core\Http_Application_Interface;
use Cake\Event\Event_Interface;
use Cake\Routing\Router;
use Closure;
use League\Container\Exception\Not_Found_Exception;
use LogicException;
use Php_Unit\Framework\Attributes\After;
/**
 * A set of methods used for defining container services
 * in test cases.
 *
 * This trait leverages the `Application.buildContainer` event
 * to inject the mocked services into the container that the
 * application uses.
 */
trait Container_Stub_Trait
{
    /**
     * The customized application class name.
     *
     * @phpstan-var class-string<\Cake\Core\HttpApplicationInterface>|class-string<\Cake\Core\ConsoleApplicationInterface>|null
     */
    protected ?string $_app_class = null;
    /**
     * The customized application constructor arguments.
     */
    protected ?array $_app_args = null;
    /**
     * The collection of container services.
     *
     * @var array<string, mixed>
     */
    private array $container_services = [];
    /**
     * Configure the application class to use in integration tests.
     *
     * @param string $class The application class name.
     * @param array|null $constructorArgs The constructor arguments for your application class.
     * @phpstan-param class-string<\Cake\Core\HttpApplicationInterface>|class-string<\Cake\Core\ConsoleApplicationInterface> $class
     */
    public function config_application(string $class, ?array $constructor_args): void
    {
        $this->_app_class = $class;
        $this->_app_args = $constructor_args;
    }
    /**
     * Create an application instance.
     *
     * Uses the configuration set in `configApplication()`.
     */
    protected function create_app(): Http_Application_Interface|Console_Application_Interface
    {
        if (class_exists(Router::class)) {
            Router::reset_routes();
        }
        if ($this->_app_class) {
            $app_class = $this->_app_class;
        } else {
            /** @var class-string<\Cake\Http\BaseApplication> $appClass */
            $app_class = Configure::read('App.namespace') . '\Application';
        }
        if (!class_exists($app_class)) {
            throw new LogicException(sprintf('Cannot load `%s` for use in integration testing.', $app_class));
        }
        $app_args = $this->_app_args ?: [CONFIG];
        $app = new $app_class(...$app_args);
        if ($this->container_services && method_exists($app, 'getEventManager')) {
            $app->get_event_manager()->on('Application.buildContainer', [$this, 'modifyContainer']);
        }
        foreach ($this->app_plugins_to_load as $plugin_name => $config) {
            if (is_array($config)) {
                $app->add_plugin($plugin_name, $config);
            } else {
                $app->add_plugin($config);
            }
        }
        return $app;
    }
    /**
     * Add a mocked service to the container.
     *
     * When the container is created the provided classname
     * will be mapped to the factory function. The factory
     * function will be used to create mocked services.
     *
     * @param string $class The class or interface you want to define.
     * @param \Closure $factory The factory function for mocked services.
     * @return $this
     */
    public function mock_service(string $class, Closure $factory)
    {
        $this->container_services[$class] = $factory;
        return $this;
    }
    /**
     * Remove a mocked service to the container.
     *
     * @param string $class The class or interface you want to remove.
     * @return $this
     */
    public function remove_mock_service(string $class)
    {
        unset($this->container_services[$class]);
        return $this;
    }
    /**
     * Wrap the application's container with one containing mocks.
     *
     * If any mocked services are defined, the application's container
     * will be replaced with one containing mocks. The original
     * container will be set as a delegate to the mock container.
     *
     * @param \Cake\Event\EventInterface $event The event
     * @param \Cake\Core\ContainerInterface $container The container to wrap.
     */
    public function modify_container(Event_Interface $event, Container_Interface $container): void
    {
        if (!$this->container_services) {
            return;
        }
        foreach ($this->container_services as $key => $factory) {
            if ($container->has($key)) {
                try {
                    $container->extend($key)->set_concrete($factory);
                } catch (Not_Found_Exception) {
                    $container->add($key, $factory);
                }
            } else {
                $container->add($key, $factory);
            }
        }
        $event->set_result($container);
    }
    /**
     * Clears any mocks that were defined and cleans
     * up application class configuration.
     */
    #[After]
    public function cleanup_container(): void
    {
        $this->_app_args = null;
        $this->_app_class = null;
        $this->container_services = [];
    }
}
// phpcs:disable
class_alias(\Cake\Core\Test_Suite\Container_Stub_Trait::class, 'Cake\TestSuite\ContainerStubTrait');
// phpcs:enable