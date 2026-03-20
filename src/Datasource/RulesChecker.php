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
 * @since         3.0.7
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource;

use Cake\Core\Exception\Cake_Exception;
use InvalidArgumentException;
/**
 * Contains logic for storing and checking rules on entities
 *
 * RulesCheckers are used by Table classes to ensure that the
 * current entity state satisfies the application logic and business rules.
 *
 * RulesCheckers afford different rules to be applied in the create and update
 * scenario.
 *
 * ### Adding rules
 *
 * Rules must be callable objects that return true/false depending on whether
 * the rule has been satisfied. You can use RulesChecker::add(), RulesChecker::addCreate(),
 * RulesChecker::addUpdate() and RulesChecker::addDelete to add rules to a checker.
 *
 * ### Running checks
 *
 * Generally a Table object will invoke the rules objects, but you can manually
 * invoke the checks by calling RulesChecker::checkCreate(), RulesChecker::checkUpdate() or
 * RulesChecker::checkDelete().
 */
class Rules_Checker
{
    /**
     * Indicates that the checking rules to apply are those used for creating entities
     *
     * @var string
     */
    public const CREATE = 'create';
    /**
     * Indicates that the checking rules to apply are those used for updating entities
     *
     * @var string
     */
    public const UPDATE = 'update';
    /**
     * Indicates that the checking rules to apply are those used for deleting entities
     *
     * @var string
     */
    public const DELETE = 'delete';
    /**
     * The list of rules to be checked on both create and update operations
     *
     * @var array<\Cake\Datasource\RuleInvoker>
     */
    protected array $_rules = [];
    /**
     * The list of rules to check during create operations
     *
     * @var array<\Cake\Datasource\RuleInvoker>
     */
    protected array $_create_rules = [];
    /**
     * The list of rules to check during update operations
     *
     * @var array<\Cake\Datasource\RuleInvoker>
     */
    protected array $_update_rules = [];
    /**
     * The list of rules to check during delete operations
     *
     * @var array<\Cake\Datasource\RuleInvoker>
     */
    protected array $_delete_rules = [];
    /**
     * Whether to use I18n functions for translating default error messages
     */
    protected bool $_use_i18n = false;
    /**
     * Constructor. Takes the options to be passed to all rules.
     *
     * @param array<string, mixed> $_options The options to pass to every rule
     */
    public function __construct(
        /**
         * List of options to pass to every callable rule
         */
        protected array $_options = []
    )
    {
        $this->_use_i18n = function_exists('\Cake\I18n\__d');
    }
    /**
     * Adds a rule that will be applied to the entity on create, update and delete
     * operations.
     *
     * ### Options
     *
     * The options array accept the following special keys:
     *
     * - `errorField`: The name of the entity field that will be marked as invalid
     *    if the rule does not pass.
     * - `message`: The error message to set to `errorField` if the rule does not pass.
     *
     * @param callable $rule A callable function or object that will return whether
     * the entity is valid or not.
     * @param array|string|null $name The alias for a rule, or an array of options.
     * @param array<string, mixed> $options List of extra options to pass to the rule callable as
     * second argument.
     * @return $this
     * @throws \Cake\Core\Exception\CakeException If a rule with the same name already exists
     */
    public function add(callable $rule, array|string|null $name = null, array $options = []): static
    {
        if (is_string($name)) {
            $this->check_name($name, $this->_rules);
            $this->_rules[$name] = $this->_add_error($rule, $name, $options);
        } else {
            $this->_rules[] = $this->_add_error($rule, $name, $options);
        }
        return $this;
    }
    /**
     * Removes a rule from the set.
     *
     * @param string $name The name of the rule to remove.
     * @return $this
     * @since 5.1.0
     */
    public function remove(string $name): static
    {
        unset($this->_rules[$name]);
        return $this;
    }
    /**
     * Adds a rule that will be applied to the entity on create operations.
     *
     * ### Options
     *
     * The options array accept the following special keys:
     *
     * - `errorField`: The name of the entity field that will be marked as invalid
     *    if the rule does not pass.
     * - `message`: The error message to set to `errorField` if the rule does not pass.
     *
     * @param callable $rule A callable function or object that will return whether
     * the entity is valid or not.
     * @param array|string|null $name The alias for a rule or an array of options.
     * @param array<string, mixed> $options List of extra options to pass to the rule callable as
     * second argument.
     * @return $this
     * @throws \Cake\Core\Exception\CakeException If a rule with the same name already exists
     */
    public function add_create(callable $rule, array|string|null $name = null, array $options = []): static
    {
        if (is_string($name)) {
            $this->check_name($name, $this->_create_rules);
            $this->_create_rules[$name] = $this->_add_error($rule, $name, $options);
        } else {
            $this->_create_rules[] = $this->_add_error($rule, $name, $options);
        }
        return $this;
    }
    /**
     * Removes a rule from the create set.
     *
     * @param string $name The name of the rule to remove.
     * @return $this
     * @since 5.1.0
     */
    public function remove_create(string $name): static
    {
        unset($this->_create_rules[$name]);
        return $this;
    }
    /**
     * Adds a rule that will be applied to the entity on update operations.
     *
     * ### Options
     *
     * The options array accept the following special keys:
     *
     * - `errorField`: The name of the entity field that will be marked as invalid
     *    if the rule does not pass.
     * - `message`: The error message to set to `errorField` if the rule does not pass.
     *
     * @param callable $rule A callable function or object that will return whether
     * the entity is valid or not.
     * @param array|string|null $name The alias for a rule, or an array of options.
     * @param array<string, mixed> $options List of extra options to pass to the rule callable as
     * second argument.
     * @return $this
     * @throws \Cake\Core\Exception\CakeException If a rule with the same name already exists
     */
    public function add_update(callable $rule, array|string|null $name = null, array $options = []): static
    {
        if (is_string($name)) {
            $this->check_name($name, $this->_update_rules);
            $this->_update_rules[$name] = $this->_add_error($rule, $name, $options);
        } else {
            $this->_update_rules[] = $this->_add_error($rule, $name, $options);
        }
        return $this;
    }
    /**
     * Removes a rule from the update set.
     *
     * @param string $name The name of the rule to remove.
     * @return $this
     * @since 5.1.0
     */
    public function remove_update(string $name): static
    {
        unset($this->_update_rules[$name]);
        return $this;
    }
    /**
     * Adds a rule that will be applied to the entity on delete operations.
     *
     * ### Options
     *
     * The options array accept the following special keys:
     *
     * - `errorField`: The name of the entity field that will be marked as invalid
     *    if the rule does not pass.
     * - `message`: The error message to set to `errorField` if the rule does not pass.
     *
     * @param callable $rule A callable function or object that will return whether
     * the entity is valid or not.
     * @param array|string|null $name The alias for a rule, or an array of options.
     * @param array<string, mixed> $options List of extra options to pass to the rule callable as
     * second argument.
     * @return $this
     * @throws \Cake\Core\Exception\CakeException If a rule with the same name already exists
     */
    public function add_delete(callable $rule, array|string|null $name = null, array $options = []): static
    {
        if (is_string($name)) {
            $this->check_name($name, $this->_delete_rules);
            $this->_delete_rules[$name] = $this->_add_error($rule, $name, $options);
        } else {
            $this->_delete_rules[] = $this->_add_error($rule, $name, $options);
        }
        return $this;
    }
    /**
     * Removes a rule from the delete set.
     *
     * @param string $name The name of the rule to remove.
     * @return $this
     * @since 5.1.0
     */
    public function remove_delete(string $name): static
    {
        unset($this->_delete_rules[$name]);
        return $this;
    }
    /**
     * Runs each of the rules by passing the provided entity and returns true if all
     * of them pass. The rules to be applied are depended on the $mode parameter which
     * can only be RulesChecker::CREATE, RulesChecker::UPDATE or RulesChecker::DELETE
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to check for validity.
     * @param string $mode Either 'create, 'update' or 'delete'.
     * @param array<string, mixed> $options Extra options to pass to checker functions.
     * @throws \InvalidArgumentException if an invalid mode is passed.
     */
    public function check(Entity_Interface $entity, string $mode, array $options = []): bool
    {
        return match ($mode) {
            self::CREATE => $this->check_create($entity, $options),
            self::UPDATE => $this->check_update($entity, $options),
            self::DELETE => $this->check_delete($entity, $options),
            default => throw new InvalidArgumentException('Wrong checking mode: ' . $mode),
        };
    }
    /**
     * Runs each of the rules by passing the provided entity and returns true if all
     * of them pass. The rules selected will be only those specified to be run on 'create'
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to check for validity.
     * @param array<string, mixed> $options Extra options to pass to checker functions.
     */
    public function check_create(Entity_Interface $entity, array $options = []): bool
    {
        return $this->_check_rules($entity, $options, array_merge(array_values($this->_rules), array_values($this->_create_rules)));
    }
    /**
     * Runs each of the rules by passing the provided entity and returns true if all
     * of them pass. The rules selected will be only those specified to be run on 'update'
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to check for validity.
     * @param array<string, mixed> $options Extra options to pass to checker functions.
     */
    public function check_update(Entity_Interface $entity, array $options = []): bool
    {
        return $this->_check_rules($entity, $options, array_merge(array_values($this->_rules), array_values($this->_update_rules)));
    }
    /**
     * Runs each of the rules by passing the provided entity and returns true if all
     * of them pass. The rules selected will be only those specified to be run on 'delete'
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to check for validity.
     * @param array<string, mixed> $options Extra options to pass to checker functions.
     */
    public function check_delete(Entity_Interface $entity, array $options = []): bool
    {
        return $this->_check_rules($entity, $options, $this->_delete_rules);
    }
    /**
     * Used by top level functions checkDelete, checkCreate and checkUpdate, this function
     * iterates an array containing the rules to be checked and checks them all.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to check for validity.
     * @param array<string, mixed> $options Extra options to pass to checker functions.
     * @param array<\Cake\Datasource\RuleInvoker> $rules The list of rules that must be checked.
     */
    protected function _check_rules(Entity_Interface $entity, array $options = [], array $rules = []): bool
    {
        $success = true;
        $options += $this->_options;
        foreach ($rules as $rule) {
            $success = $rule($entity, $options) && $success;
        }
        return $success;
    }
    /**
     * Utility method for decorating any callable so that if it returns false, the correct
     * property in the entity is marked as invalid.
     *
     * @param callable $rule The rule to decorate
     * @param array|string|null $name The alias for a rule or an array of options
     * @param array<string, mixed> $options The options containing the error message and field.
     */
    protected function _add_error(callable $rule, array|string|null $name = null, array $options = []): Rule_Invoker
    {
        if (is_array($name)) {
            $options = $name;
            $name = null;
        }
        if (!$rule instanceof Rule_Invoker) {
            $rule = new Rule_Invoker($rule, $name, $options);
        } else {
            $rule->set_options($options)->set_name($name);
        }
        return $rule;
    }
    /**
     * Checks that a rule with the same name doesn't already exist
     *
     * @param string $name The name to check
     * @param array<\Cake\Datasource\RuleInvoker> $rules The rules array to check
     * @throws \Cake\Core\Exception\CakeException
     */
    protected function check_name(string $name, array $rules): void
    {
        if (array_key_exists($name, $rules)) {
            throw new Cake_Exception('A rule with the same name already exists');
        }
    }
}