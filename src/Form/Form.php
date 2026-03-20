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
namespace Cake\Form;

use function Cake\Core\Deprecation_Warning;
use Cake\Event\Event_Dispatcher_Interface;
use Cake\Event\Event_Dispatcher_Trait;
use Cake\Event\Event_Listener_Interface;
use Cake\Event\Event_Manager;
use Cake\Utility\Hash;
use Cake\Validation\Validator_Aware_Interface;
use Cake\Validation\Validator_Aware_Trait;
use ReflectionMethod;
/**
 * Form abstraction used to create forms not tied to ORM backed models,
 * or to other permanent datastores. Ideal for implementing forms on top of
 * API services, or contact forms.
 *
 * ### Building a form
 *
 * This class is most useful when subclassed. In a subclass you
 * should define the `_buildSchema`, `validationDefault` and optionally,
 * the `process` methods. These allow you to declare your form's
 * fields, validation and primary action respectively.
 *
 * Forms are conventionally placed in the `App\Form` namespace.
 *
 * @implements \Cake\Event\EventDispatcherInterface<\Cake\Form\Form>
 */
class Form implements Event_Listener_Interface, Event_Dispatcher_Interface, Validator_Aware_Interface
{
    /**
     * @use \Cake\Event\EventDispatcherTrait<\Cake\Form\Form>
     */
    use Event_Dispatcher_Trait;
    use Validator_Aware_Trait;
    /**
     * Name of default validation set.
     *
     * @var string
     */
    public const DEFAULT_VALIDATOR = 'default';
    /**
     * The alias this object is assigned to validators as.
     *
     * @var string
     */
    public const VALIDATOR_PROVIDER_NAME = 'form';
    /**
     * The name of the event dispatched when a validator has been built.
     *
     * @var string
     */
    public const BUILD_VALIDATOR_EVENT = 'Form.buildValidator';
    /**
     * Schema class.
     *
     * @phpstan-var class-string<\Cake\Form\Schema>
     */
    protected string $_schema_class = Schema::class;
    /**
     * The schema used by this form.
     */
    protected ?Schema $_schema = null;
    /**
     * The errors if any
     */
    protected array $_errors = [];
    /**
     * Form's data.
     */
    protected array $_data = [];
    /**
     * Constructor
     *
     * @param \Cake\Event\EventManager|null $eventManager The event manager.
     *  Defaults to a new instance.
     */
    public function __construct(?Event_Manager $event_manager = null)
    {
        if ($event_manager !== null) {
            $this->set_event_manager($event_manager);
        }
        $this->get_event_manager()->on($this);
    }
    /**
     * Get the Form callbacks this form is interested in.
     *
     * The conventional method map is:
     *
     * - Form.buildValidator => buildValidator
     *
     * @return array<string, mixed>
     */
    public function implemented_events(): array
    {
        if (method_exists($this, 'buildValidator')) {
            return [self::BUILD_VALIDATOR_EVENT => 'buildValidator'];
        }
        return [];
    }
    /**
     * Set the schema for this form.
     *
     * @since 4.1.0
     * @param \Cake\Form\Schema $schema The schema to set
     * @return $this
     */
    public function set_schema(Schema $schema): static
    {
        $this->_schema = $schema;
        return $this;
    }
    /**
     * Get the schema for this form.
     *
     * This method will call `_buildSchema()` when the schema
     * is first built. This hook method lets you configure the
     * schema or load a pre-defined one.
     *
     * @since 4.1.0
     * @return \Cake\Form\Schema the schema instance.
     */
    public function get_schema(): Schema
    {
        $this->_schema ??= $this->_build_schema(new $this->_schema_class());
        return $this->_schema;
    }
    /**
     * A hook method intended to be implemented by subclasses.
     *
     * You can use this method to define the schema using
     * the methods on {@link \Cake\Form\Schema}, or loads a pre-defined
     * schema from a concrete class.
     *
     * @param \Cake\Form\Schema $schema The schema to customize.
     * @return \Cake\Form\Schema The schema to use.
     */
    protected function _build_schema(Schema $schema): Schema
    {
        return $schema;
    }
    /**
     * Used to check if $data passes this form's validation.
     *
     * @param array $data The data to check.
     * @param string|null $validator Validator name.
     * @return bool Whether the data is valid.
     * @throws \RuntimeException If validator is invalid.
     */
    public function validate(array $data, ?string $validator = null): bool
    {
        $this->_errors = $this->get_validator($validator ?: static::DEFAULT_VALIDATOR)->validate($data);
        return $this->_errors === [];
    }
    /**
     * Get the errors in the form
     *
     * Will return the errors from the last call
     * to `validate()` or `execute()`.
     *
     * @return array Last set validation errors.
     */
    public function get_errors(): array
    {
        return $this->_errors;
    }
    /**
     * Returns validation errors for the given field
     *
     * Supports dot notation for nested fields. For example:
     * - `$form->getError('Common.field_name')`
     * - `$form->getError('parent.level.deep_field')`
     *
     * @param string $field Field name to get the errors from. Supports dot notation for nested fields.
     * @return array The validation errors for the given field.
     */
    public function get_error(string $field): array
    {
        if (isset($this->_errors[$field])) {
            return $this->_errors[$field];
        }
        $error = Hash::get($this->_errors, $field);
        return is_array($error) ? $error : [];
    }
    /**
     * Set the errors in the form.
     *
     * ```
     * $errors = [
     *      'field_name' => ['rule_name' => 'message']
     * ];
     *
     * $form->setErrors($errors);
     * ```
     *
     * @param array $errors Errors list.
     * @return $this
     */
    public function set_errors(array $errors): static
    {
        $this->_errors = $errors;
        return $this;
    }
    /**
     * Execute the form if it is valid.
     *
     * First validates the form, then calls the `process()` hook method.
     * This hook method can be implemented in subclasses to perform
     * the action of the form. This may be sending email, interacting
     * with a remote API, or anything else you may need.
     *
     * ### Options:
     *
     * - validate: Set to `false` to disable validation. Can also be a string of the validator ruleset to be applied.
     *   Defaults to `true`/`'default'`.
     *
     * @param array $data Form data.
     * @param array<string, mixed> $options List of options.
     * @return bool False on validation failure, otherwise returns the
     *   result of the `process()` method.
     */
    public function execute(array $data, array $options = []): bool
    {
        // check for deprecated _execute() method - https://github.com/cakephp/cakephp/pull/18725
        $child_class = static::class;
        $parent_class = self::class;
        $method = new ReflectionMethod($child_class, '_execute');
        $has_overwritten_execute = $method->get_declaring_class()->get_name() !== $parent_class;
        $this->_data = $data;
        $options += ['validate' => true];
        if ($options['validate'] === false) {
            if ($has_overwritten_execute) {
                deprecation_warning('5.3.0', 'The _execute() method is deprecated. Override the process() method instead.');
                return $this->_execute($data);
            }
            return $this->process($data);
        }
        $validator = $options['validate'] === true ? static::DEFAULT_VALIDATOR : $options['validate'];
        $validate_result = $this->validate($data, $validator);
        if ($has_overwritten_execute) {
            deprecation_warning('5.3.0', 'The _execute() method is deprecated. Override the process() method instead.');
            return $validate_result && $this->_execute($data);
        }
        return $validate_result && $this->process($data);
    }
    /**
     * Hook method to be implemented in subclasses.
     *
     * Used by `execute()` to execute the form's action.
     *
     * @param array $data Form data.
     * @deprecated 5.3.0 Override process() instead.
     */
    protected function _execute(array $data): bool
    {
        return $this->process($data);
    }
    /**
     * Hook method to be implemented in subclasses.
     *
     * Used by `execute()` to execute the form's action.
     *
     * @param array $data Form data.
     */
    protected function process(array $data): bool
    {
        return true;
    }
    /**
     * Get field data.
     *
     * @param string|null $field The field name or null to get data array with
     *   all fields.
     */
    public function get_data(?string $field = null): mixed
    {
        if ($field === null) {
            return $this->_data;
        }
        return Hash::get($this->_data, $field);
    }
    /**
     * Saves a variable or an associative array of variables for use inside form data.
     *
     * @param array|string $name The key to write, can be a dot notation value.
     * Alternatively can be an array containing key(s) and value(s).
     * @param mixed $value Value to set for var
     * @return $this
     */
    public function set(array|string $name, mixed $value = null): static
    {
        $write = $name;
        if (!is_array($name)) {
            $write = [$name => $value];
        }
        /** @var array<string, mixed> $write */
        foreach ($write as $key => $val) {
            $this->_data = Hash::insert($this->_data, $key, $val);
        }
        return $this;
    }
    /**
     * Set form data.
     *
     * @param array $data Data array.
     * @return $this
     */
    public function set_data(array $data): static
    {
        $this->_data = $data;
        return $this;
    }
    /**
     * Get the printable version of a Form instance.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $special = ['_schema' => $this->get_schema()->__debugInfo(), '_errors' => $this->get_errors(), '_validator' => $this->get_validator()->__debugInfo()];
        return $special + get_object_vars($this);
    }
}