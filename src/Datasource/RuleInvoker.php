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
 * @since         3.2.12
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource;

use Closure;
/**
 * Contains logic for invoking an application rule.
 *
 * Combined with {@link \Cake\Datasource\RulesChecker} as an implementation
 * detail to de-duplicate rule decoration and provide cleaner separation
 * of duties.
 *
 * @internal
 */
class Rule_Invoker
{
    /**
     * Rule callable
     *
     * @var callable
     */
    protected $rule;
    /**
     * Constructor
     *
     * ### Options
     *
     * - `errorField` The field errors should be set onto.
     * - `message` The error message.
     *
     * Individual rules may have additional options that can be
     * set here. Any options will be passed into the rule as part of the
     * rule $scope.
     *
     * @param callable $rule The rule to be invoked.
     * @param string|null $name The name of the rule. Used in error messages.
     * @param array<string, mixed> $options The options for the rule. See above.
     */
    public function __construct(
        callable $rule,
        /**
         * The rule name
         */
        protected ?string $name,
        /**
         * Rule options
         */
        protected array $options = []
    )
    {
        $this->rule = $rule;
    }
    /**
     * Set options for the rule invocation.
     *
     * Old options will be merged with the new ones.
     *
     * @param array<string, mixed> $options The options to set.
     * @return $this
     */
    public function set_options(array $options): static
    {
        $this->options = $options + $this->options;
        return $this;
    }
    /**
     * Set the rule name.
     *
     * Only truthy names will be set.
     *
     * @param string|null $name The name to set.
     * @return $this
     */
    public function set_name(?string $name): static
    {
        if ($name) {
            $this->name = $name;
        }
        return $this;
    }
    /**
     * Invoke the rule.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity the rule
     *   should apply to.
     * @param array $scope The rule's scope/options.
     * @return bool Whether the rule passed.
     */
    public function __invoke(Entity_Interface $entity, array $scope): bool
    {
        $rule = $this->rule;
        $pass = $rule($entity, $this->options + $scope);
        if ($pass === true) {
            return true;
        }
        $message = $this->options['message'] ?? 'invalid';
        if (is_string($pass)) {
            $message = $pass;
        }
        if ($message instanceof Closure) {
            $message = $message($entity, $this->options + $scope);
        }
        if ($this->name) {
            $message = [$this->name => $message];
        } else {
            $message = [$message];
        }
        $error_field = $this->options['errorField'] ?? $this->name ?? '_rule';
        $entity->set_error($error_field, $message);
        if ($entity instanceof Invalid_Property_Interface && isset($entity->{$error_field})) {
            $invalid_value = $entity->{$error_field};
            $entity->set_invalid_field($error_field, $invalid_value);
        }
        return false;
    }
}