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
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource;

use function Cake\Core\Plugin_Split;
use Cake\Datasource\Exception\Missing_Model_Exception;
use Cake\Datasource\Locator\Locator_Interface;
use UnexpectedValueException;
/**
 * Provides functionality for loading table classes
 * and other repositories onto properties of the host object.
 *
 * Example users of this trait are {@link \Cake\Controller\Controller} and
 * {@link \Cake\Command\Command}.
 */
trait Model_Aware_Trait
{
    /**
     * This object's primary model class name. Should be a plural form.
     * CakePHP will not inflect the name.
     *
     * Example: For an object named 'Comments', the modelClass would be 'Comments'.
     * Plugin classes should use `Plugin.Comments` style names to correctly load
     * models from the correct plugin.
     *
     * Use empty string to not use auto-loading on this object. Null auto-detects based on
     * controller name.
     */
    protected ?string $model_class = null;
    /**
     * A list of overridden model factory functions.
     *
     * @var array<callable|\Cake\Datasource\Locator\LocatorInterface>
     */
    protected array $_model_factories = [];
    /**
     * The model type to use.
     */
    protected string $_model_type = 'Table';
    /**
     * Set the modelClass property based on conventions.
     *
     * If the property is already set it will not be overwritten
     *
     * @param string $name Class name.
     */
    protected function _set_model_class(string $name): void
    {
        $this->model_class ??= $name;
    }
    /**
     * Fetch or construct a model instance from a locator.
     *
     * Uses a modelFactory based on `$modelType` to fetch and construct a `RepositoryInterface`
     * and return it. The default `modelType` can be defined with `setModelType()`.
     *
     * Unlike `loadModel()` this method will *not* set an object property.
     *
     * If a repository provider does not return an object a MissingModelException will
     * be thrown.
     *
     * @param string|null $modelClass Name of model class to load. Defaults to $this->modelClass.
     *  The name can be an alias like `'Post'` or FQCN like `App\Model\Table\PostsTable::class`.
     * @param string|null $modelType The type of repository to load. Defaults to the getModelType() value.
     * @return \Cake\Datasource\RepositoryInterface The model instance created.
     * @throws \Cake\Datasource\Exception\MissingModelException If the model class cannot be found.
     * @throws \UnexpectedValueException If $modelClass argument is not provided
     *   and ModelAwareTrait::$modelClass property value is empty.
     */
    public function fetch_model(?string $model_class = null, ?string $model_type = null): Repository_Interface
    {
        $model_class ??= $this->model_class;
        if (!$model_class) {
            throw new UnexpectedValueException('Default modelClass is empty');
        }
        $model_type ??= $this->get_model_type();
        $options = [];
        if (!str_contains($model_class, '\\')) {
            [, $alias] = plugin_split($model_class, true);
        } else {
            $options['className'] = $model_class;
            $alias = substr($model_class, strrpos($model_class, '\\') + 1, -strlen((string) $model_type));
            $model_class = $alias;
        }
        $factory = $this->_model_factories[$model_type] ?? Factory_Locator::get($model_type);
        if ($factory instanceof Locator_Interface) {
            $instance = $factory->get($model_class, $options);
        } else {
            $instance = $factory($model_class, $options);
        }
        if ($instance) {
            return $instance;
        }
        throw new Missing_Model_Exception([$model_class, $model_type]);
    }
    /**
     * Override a existing callable to generate repositories of a given type.
     *
     * @param string $type The name of the repository type the factory function is for.
     * @param \Cake\Datasource\Locator\LocatorInterface|callable $factory The factory function used to create instances.
     */
    public function model_factory(string $type, Locator_Interface|callable $factory): void
    {
        $this->_model_factories[$type] = $factory;
    }
    /**
     * Get the model type to be used by this class
     */
    public function get_model_type(): string
    {
        return $this->_model_type;
    }
    /**
     * Set the model type to be used by this class
     *
     * @param string $modelType The model type
     * @return $this
     */
    public function set_model_type(string $model_type)
    {
        $this->_model_type = $model_type;
        return $this;
    }
}