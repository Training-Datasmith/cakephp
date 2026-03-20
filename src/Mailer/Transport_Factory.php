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
 * @since         3.7.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Mailer;

use Cake\Core\Static_Config_Trait;
use InvalidArgumentException;
/**
 * Factory class for generating email transport instances.
 */
class Transport_Factory
{
    use Static_Config_Trait;
    /**
     * Transport Registry used for creating and using transport instances.
     */
    protected static Transport_Registry $_registry;
    /**
     * An array mapping url schemes to fully qualified Transport class names
     *
     * @var array<string, string>
     * @phpstan-var array<string, class-string>
     */
    protected static array $_dsn_class_map = ['debug' => Transport\Debug_Transport::class, 'mail' => Transport\Mail_Transport::class, 'smtp' => Transport\Smtp_Transport::class];
    /**
     * Returns the Transport Registry used for creating and using transport instances.
     */
    public static function get_registry(): Transport_Registry
    {
        return static::$_registry ??= new Transport_Registry();
    }
    /**
     * Sets the Transport Registry instance used for creating and using transport instances.
     *
     * Also allows for injecting of a new registry instance.
     *
     * @param \Cake\Mailer\TransportRegistry $registry Injectable registry object.
     */
    public static function set_registry(Transport_Registry $registry): void
    {
        static::$_registry = $registry;
    }
    /**
     * Finds and builds the instance of the required transport class.
     *
     * @param string $name Name of the config array that needs a transport instance built
     * @throws \InvalidArgumentException When a transport cannot be created.
     */
    protected static function _build_transport(string $name): void
    {
        if (!isset(static::$_config[$name])) {
            throw new InvalidArgumentException(sprintf('The `%s` transport configuration does not exist', $name));
        }
        if (is_array(static::$_config[$name]) && empty(static::$_config[$name]['className'])) {
            throw new InvalidArgumentException(sprintf('Transport config `%s` is invalid, the required `className` option is missing', $name));
        }
        static::get_registry()->load($name, static::$_config[$name]);
    }
    /**
     * Get transport instance.
     *
     * @param string $name Config name.
     */
    public static function get(string $name): Abstract_Transport
    {
        $registry = static::get_registry();
        if (isset($registry->{$name})) {
            return $registry->{$name};
        }
        static::_build_transport($name);
        return $registry->{$name};
    }
}