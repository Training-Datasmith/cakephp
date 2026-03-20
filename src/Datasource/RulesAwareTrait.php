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

use ArrayObject;
use Cake\Event\Event_Dispatcher_Interface;
/**
 * A trait that allows a class to build and apply application.
 * rules.
 *
 * If the implementing class also implements EventAwareTrait, then
 * events will be emitted when rules are checked.
 *
 * The implementing class is expected to define the `RULES_CLASS` constant
 * if they need to customize which class is used for rules objects.
 */
trait Rules_Aware_Trait
{
    /**
     * The domain rules to be applied to entities saved by this table
     */
    protected ?Rules_Checker $_rules_checker = null;
    /**
     * Returns whether the passed entity complies with all the rules stored in
     * the rules checker.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to check for validity.
     * @param string $operation The operation being run. Either 'create', 'update' or 'delete'.
     * @param \ArrayObject<string, mixed>|array|null $options The options To be passed to the rules.
     */
    public function check_rules(Entity_Interface $entity, string $operation = Rules_Checker::CREATE, ArrayObject|array|null $options = null): bool
    {
        $rules = $this->rules_checker();
        $options = $options ?: new ArrayObject();
        $options = is_array($options) ? new ArrayObject($options) : $options;
        $has_events = $this instanceof Event_Dispatcher_Interface;
        if ($has_events) {
            $event = $this->dispatch_event('Model.beforeRules', compact('entity', 'options', 'operation'));
            if ($event->is_stopped()) {
                return $event->get_result();
            }
        }
        $result = $rules->check($entity, $operation, $options->get_array_copy());
        if ($has_events) {
            $event = $this->dispatch_event('Model.afterRules', compact('entity', 'options', 'result', 'operation'));
            if ($event->is_stopped()) {
                return $event->get_result();
            }
        }
        return $result;
    }
    /**
     * Returns the RulesChecker for this instance.
     *
     * A RulesChecker object is used to test an entity for validity
     * on rules that may involve complex logic or data that
     * needs to be fetched from relevant datasources.
     *
     * @see \Cake\Datasource\RulesChecker
     */
    public function rules_checker(): Rules_Checker
    {
        if ($this->_rules_checker !== null) {
            return $this->_rules_checker;
        }
        /** @var class-string<\Cake\Datasource\RulesChecker> $class */
        $class = defined('static::RULES_CLASS') ? static::RULES_CLASS : Rules_Checker::class;
        /**
         * @phpstan-ignore-next-line
         */
        $this->_rules_checker = $this->build_rules(new $class(['repository' => $this]));
        $this->dispatch_event('Model.buildRules', ['rules' => $this->_rules_checker]);
        return $this->_rules_checker;
    }
    /**
     * Returns a RulesChecker object after modifying the one that was supplied.
     *
     * Subclasses should override this method in order to initialize the rules to be applied to
     * entities saved by this instance.
     *
     * @param \Cake\Datasource\RulesChecker $rules The rules object to be modified.
     */
    public function build_rules(Rules_Checker $rules): Rules_Checker
    {
        return $rules;
    }
}