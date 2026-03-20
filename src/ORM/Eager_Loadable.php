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
namespace Cake\ORM;

use Cake\Database\Exception\Database_Exception;
/**
 * Represents a single level in the associations tree to be eagerly loaded
 * for a specific query. This contains all the information required to
 * fetch the results from the database from an associations and all its children
 * levels.
 *
 * @internal
 */
class Eager_Loadable
{
    /**
     * A list of other associations to load from this level.
     *
     * @var array<string, \Cake\ORM\EagerLoadable>
     */
    protected array $_associations = [];
    /**
     * The Association class instance to use for loading the records.
     */
    protected ?Association $_instance = null;
    /**
     * A list of options to pass to the association object for loading
     * the records.
     *
     * @var array<string, mixed>
     */
    protected array $_config = [];
    /**
     * A dotted separated string representing the path of associations
     * that should be followed to fetch this level.
     */
    protected string $_alias_path;
    /**
     * A dotted separated string representing the path of entity properties
     * in which results for this level should be placed.
     *
     * For example, in the following nested property:
     *
     * ```
     *  $article->author->company->country
     * ```
     *
     * The property path of `country` will be `author.company`
     */
    protected ?string $_property_path = null;
    /**
     * Whether this level can be fetched using a join.
     */
    protected bool $_can_be_joined = false;
    /**
     * Whether this level was meant for a "matching" fetch
     * operation
     */
    protected ?bool $_for_matching = null;
    /**
     * The property name where the association result should be nested
     * in the result.
     *
     * For example, in the following nested property:
     *
     * ```
     *  $article->author->company->country
     * ```
     *
     * The target property of `country` will be just `country`
     */
    protected ?string $_target_property = null;
    /**
     * Constructor. The $config parameter accepts the following array
     * keys:
     *
     * - associations
     * - instance
     * - config
     * - canBeJoined
     * - aliasPath
     * - propertyPath
     * - forMatching
     * - targetProperty
     *
     * The keys maps to the settable properties in this class.
     *
     * @param string $_name The Association name.
     * @param array<string, mixed> $config The list of properties to set.
     */
    public function __construct(
        /**
         * The name of the association to load.
         */
        protected string $_name,
        array $config = []
    )
    {
        $allowed = ['associations', 'instance', 'config', 'canBeJoined', 'aliasPath', 'propertyPath', 'forMatching', 'targetProperty'];
        foreach ($allowed as $property) {
            if (isset($config[$property])) {
                $this->{'_' . $property} = $config[$property];
            }
        }
    }
    /**
     * Adds a new association to be loaded from this level.
     *
     * @param string $name The association name.
     * @param \Cake\ORM\EagerLoadable $association The association to load.
     */
    public function add_association(string $name, Eager_Loadable $association): void
    {
        $this->_associations[$name] = $association;
    }
    /**
     * Returns the Association class instance to use for loading the records.
     *
     * @return array<string, \Cake\ORM\EagerLoadable>
     */
    public function associations(): array
    {
        return $this->_associations;
    }
    /**
     * Gets the Association class instance to use for loading the records.
     *
     * @throws \Cake\Database\Exception\DatabaseException
     */
    public function instance(): Association
    {
        if ($this->_instance === null) {
            throw new Database_Exception('No instance set.');
        }
        return $this->_instance;
    }
    /**
     * Gets a dot separated string representing the path of associations
     * that should be followed to fetch this level.
     */
    public function alias_path(): string
    {
        return $this->_alias_path;
    }
    /**
     * Gets a dot separated string representing the path of entity properties
     * in which results for this level should be placed.
     *
     * For example, in the following nested property:
     *
     * ```
     *  $article->author->company->country
     * ```
     *
     * The property path of `country` will be `author.company`
     */
    public function property_path(): ?string
    {
        return $this->_property_path;
    }
    /**
     * Sets whether this level can be fetched using a join.
     *
     * @param bool $possible The value to set.
     * @return $this
     */
    public function set_can_be_joined(bool $possible): static
    {
        $this->_can_be_joined = $possible;
        return $this;
    }
    /**
     * Gets whether this level can be fetched using a join.
     */
    public function can_be_joined(): bool
    {
        return $this->_can_be_joined;
    }
    /**
     * Sets the list of options to pass to the association object for loading
     * the records.
     *
     * @param array<string, mixed> $config The value to set.
     * @return $this
     */
    public function set_config(array $config): static
    {
        $this->_config = $config;
        return $this;
    }
    /**
     * Gets the list of options to pass to the association object for loading
     * the records.
     *
     * @return array<string, mixed>
     */
    public function get_config(): array
    {
        return $this->_config;
    }
    /**
     * Gets whether this level was meant for a
     * "matching" fetch operation.
     */
    public function for_matching(): ?bool
    {
        return $this->_for_matching;
    }
    /**
     * The property name where the result of this association
     * should be nested at the end.
     *
     * For example, in the following nested property:
     *
     * ```
     *  $article->author->company->country
     * ```
     *
     * The target property of `country` will be just `country`
     */
    public function target_property(): ?string
    {
        return $this->_target_property;
    }
    /**
     * Returns a representation of this object that can be passed to
     * Cake\ORM\EagerLoader::contain()
     *
     * @return array<string, array>
     */
    public function as_contain_array(): array
    {
        $associations = [];
        foreach ($this->_associations as $assoc) {
            $associations += $assoc->as_contain_array();
        }
        $config = $this->_config;
        if ($this->_for_matching !== null) {
            $config = ['matching' => $this->_for_matching] + $config;
        }
        return [$this->_name => ['associations' => $associations, 'config' => $config]];
    }
    /**
     * Handles cloning eager loadables.
     */
    public function __clone()
    {
        foreach ($this->_associations as $i => $association) {
            $this->_associations[$i] = clone $association;
        }
    }
}