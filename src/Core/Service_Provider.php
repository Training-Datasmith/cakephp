<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         4.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Core;

use League\Container\Definition_Container_Interface;
use League\Container\Service_Provider\Abstract_Service_Provider;
use League\Container\Service_Provider\Bootable_Service_Provider_Interface;
use LogicException;
/**
 * Container ServiceProvider
 *
 * Service provider bundle related services together helping
 * to organize your application's dependencies. They also help
 * improve performance of applications with many services by
 * allowing service registration to be deferred until services are needed.
 */
abstract class Service_Provider extends Abstract_Service_Provider implements Bootable_Service_Provider_Interface
{
    /**
     * List of ids of services this provider provides.
     *
     * @var array<string>
     * @see ServiceProvider::provides()
     */
    protected array $provides = [];
    /**
     * Get the container.
     *
     * @return \Cake\Core\ContainerInterface
     */
    public function get_container(): Definition_Container_Interface
    {
        $container = parent::get_container();
        assert($container instanceof Container_Interface, sprintf('Unexpected container type. Expected `%s` got `%s` instead.', Container_Interface::class, get_debug_type($container)));
        return $container;
    }
    /**
     * Delegate to the bootstrap() method
     *
     * This method wraps the league/container function so users
     * only need to use the CakePHP bootstrap() interface.
     */
    public function boot(): void
    {
        $this->bootstrap($this->get_container());
    }
    /**
     * Bootstrap hook for ServiceProviders
     *
     * This hook should be implemented if your service provider
     * needs to register additional service providers, load configuration
     * files or do any other work when the service provider is added to the
     * container.
     *
     * @param \Cake\Core\ContainerInterface $container The container to add services to.
     */
    public function bootstrap(Container_Interface $container): void
    {
    }
    /**
     * Call the abstract services() method.
     *
     * This method primarily exists as a shim between the interface
     * that league/container has and the one we want to offer in CakePHP.
     */
    public function register(): void
    {
        $this->services($this->get_container());
    }
    /**
     * The provides method is a way to let the container know that a service
     * is provided by this service provider.
     *
     * Every service registered via this service provider must have an
     * alias added to this array or it will be ignored.
     *
     * @param string $id Identifier.
     */
    public function provides(string $id): bool
    {
        if (!$this->provides) {
            throw new LogicException('The property `$provides` should contain a list with service ids for this service provider');
        }
        return in_array($id, $this->provides, true);
    }
    /**
     * Register the services in a provider.
     *
     * All services registered in this method should also be included in the $provides
     * property so that services can be located.
     *
     * @param \Cake\Core\ContainerInterface $container The container to add services to.
     */
    abstract public function services(Container_Interface $container): void;
}