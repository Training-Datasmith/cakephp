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
 * @since         4.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Controller\Component;

use Cake\Controller\Component;
use Cake\Controller\Exception\Form_Protection_Exception;
use Cake\Core\Configure;
use Cake\Event\Event_Interface;
use Cake\Form\Form_Protector;
use Cake\Http\Response;
use Cake\Routing\Router;
use Closure;
/**
 * Protects against form tampering. It ensures that:
 *
 * - Form's action (URL) is not modified.
 * - Unknown / extra fields are not added to the form.
 * - Existing fields have not been removed from the form.
 * - Values of hidden inputs have not been changed.
 *
 * @phpstan-property array{validate:bool, unlockedFields:array, unlockedActions:array, validationFailureCallback:?\Closure} $_config
 */
class Form_Protection_Component extends Component
{
    /**
     * Default message used for exceptions thrown.
     *
     * @var string
     */
    public const DEFAULT_EXCEPTION_MESSAGE = 'Form tampering protection token validation failed.';
    /**
     * Default config
     *
     * - `validate` - Whether to validate request body / data. Set to false to disable
     *   for data coming from 3rd party services, etc.
     * - `unlockedFields` - Form fields to exclude from validation. Fields can
     *   be unlocked either in the Component, or with FormHelper::unlockField().
     *   Fields that have been unlocked are not required to be part of the POST
     *   and hidden unlocked fields do not have their values checked.
     * - `unlockedActions` - Actions to exclude from POST validation checks.
     * - `validationFailureCallback` - Callback to call in case of validation
     *   failure. Must be a valid Closure. Unset by default in which case
     *   exception is thrown on validation failure.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['validate' => true, 'unlockedFields' => [], 'unlockedActions' => [], 'validationFailureCallback' => null];
    /**
     * Get Session id for FormProtector
     * Must be the same as in FormHelper
     */
    protected function _get_session_id(): string
    {
        $session = $this->get_controller()->get_request()->get_session();
        $session->start();
        return $session->id();
    }
    /**
     * Component startup.
     *
     * Token check happens here.
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event An Event instance
     */
    public function startup(Event_Interface $event): ?Response
    {
        $request = $this->get_controller()->get_request();
        $data = $request->get_parsed_body();
        $has_data = $data || $request->is(['put', 'post', 'delete', 'patch']);
        if (!in_array($request->get_param('action'), $this->_config['unlockedActions'], true) && $has_data && $this->_config['validate']) {
            $session_id = $this->_get_session_id();
            $url = Router::url($request->get_request_target());
            $form_protector = new Form_Protector($this->_config);
            $is_valid = $form_protector->validate($data, $url, $session_id);
            if (!$is_valid) {
                $event->set_result($this->validation_failure($form_protector));
                return null;
            }
        }
        $token = ['unlockedFields' => $this->_config['unlockedFields']];
        $request = $request->with_attribute('formTokenData', ['unlockedFields' => $token['unlockedFields']]);
        if (is_array($data)) {
            unset($data['_Token']);
            $request = $request->with_parsed_body($data);
        }
        $this->get_controller()->set_request($request);
        return null;
    }
    /**
     * Events supported by this component.
     *
     * @return array<string, mixed>
     */
    public function implemented_events(): array
    {
        return ['Controller.startup' => 'startup'];
    }
    /**
     * Throws a 400 - Bad request exception or calls custom callback.
     *
     * If `validationFailureCallback` config is specified, it will use this
     * callback by executing the method passing the argument as exception.
     *
     * @param \Cake\Form\FormProtector $formProtector Form Protector instance.
     * @return \Cake\Http\Response|null If specified, validationFailureCallback's response, or no return otherwise.
     * @throws \Cake\Controller\Exception\FormProtectionException
     */
    protected function validation_failure(Form_Protector $form_protector): ?Response
    {
        if (Configure::read('debug')) {
            $exception = new Form_Protection_Exception($form_protector->get_error());
        } else {
            $exception = new Form_Protection_Exception(static::DEFAULT_EXCEPTION_MESSAGE);
        }
        if ($this->_config['validationFailureCallback']) {
            return $this->execute_callback($this->_config['validationFailureCallback'], $exception);
        }
        throw $exception;
    }
    /**
     * Execute callback.
     *
     * @param \Closure $callback Callback
     * @param \Cake\Controller\Exception\FormProtectionException $exception Exception instance.
     */
    protected function execute_callback(Closure $callback, Form_Protection_Exception $exception): ?Response
    {
        return $callback($exception);
    }
}