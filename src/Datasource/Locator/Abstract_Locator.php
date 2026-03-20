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
 * @since         4.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource\Locator;

use Cake\Core\Exception\Cake_Exception;
use Cake\Datasource\Repository_Interface;
/**
 * Provides an abstract registry/factory for repository objects.
 *
 * @template TRepo of \Cake\Datasource\RepositoryInterface
 * @implements \Cake\Datasource\Locator\LocatorInterface<TRepo>
 */
abstract class Abstract_Locator implements Locator_Interface
{
    /**
     * Instances that belong to the registry.
     *
     * @var array<string, TRepo>
     */
    protected array $instances = [];
    /**
     * Contains a list of options that were passed to get() method.
     *
     * @var array<string, array>
     */
    protected array $options = [];
    /**
     * {@inheritDoc}
     *
     * @param string $alias The alias name you want to get.
     * @param array<string, mixed> $options The options you want to build the table with.
     * @return TRepo
     * @throws \Cake\Core\Exception\CakeException When trying to get alias for which instance
     *   has already been created with different options.
     */
    public function get(string $alias, array $options = []): Repository_Interface
    {
        $store_options = $options;
        unset($store_options['allowFallbackClass']);
        if (isset($this->instances[$alias])) {
            if ($store_options && isset($this->options[$alias]) && $this->options[$alias] !== $store_options) {
                throw new Cake_Exception(sprintf('You cannot configure `%s`, it already exists in the registry.', $alias));
            }
            return $this->instances[$alias];
        }
        $this->options[$alias] = $store_options;
        return $this->instances[$alias] = $this->create_instance($alias, $options);
    }
    /**
     * Create an instance of a given classname.
     *
     * @param string $alias Repository alias.
     * @param array<string, mixed> $options The options you want to build the instance with.
     * @return TRepo
     */
    abstract protected function create_instance(string $alias, array $options): Repository_Interface;
    /**
     * @inheritDoc
     */
    public function set(string $alias, Repository_Interface $repository): Repository_Interface
    {
        return $this->instances[$alias] = $repository;
    }
    /**
     * @inheritDoc
     */
    public function exists(string $alias): bool
    {
        return isset($this->instances[$alias]);
    }
    /**
     * @inheritDoc
     */
    public function remove(string $alias): void
    {
        unset($this->instances[$alias], $this->options[$alias]);
    }
    /**
     * @inheritDoc
     */
    public function clear(): void
    {
        $this->instances = [];
        $this->options = [];
    }
}