<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright 2005-2011, Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         5.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Core;

use Cake\Event\Event_Manager_Interface;
interface Event_Aware_Application_Interface
{
    /**
     * Register application events.
     *
     * @param \Cake\Event\EventManagerInterface $eventManager The global event manager to register listeners on
     */
    public function events(Event_Manager_Interface $event_manager): Event_Manager_Interface;
    /**
     * @param \Cake\Event\EventManagerInterface $eventManager The global event manager to register listeners on
     */
    public function plugin_events(Event_Manager_Interface $event_manager): Event_Manager_Interface;
}