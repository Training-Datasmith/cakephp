<?php

declare (strict_types=1);
/**
 * CakePHP(tm) :  Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakefoundation.org CakePHP(tm) Project
 * @since         2.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Log\Engine;

use ArrayObject;
use function Cake\Core\Deprecation_Warning;
use Cake\Core\Instance_Config_Trait;
use Cake\Log\Formatter\Abstract_Formatter;
use Cake\Log\Formatter\Default_Formatter;
use JsonSerializable;
use Psr\Log\Abstract_Logger;
use Serializable;
use Stringable;
/**
 * Base log engine class.
 */
abstract class Base_Log extends Abstract_Logger
{
    use Instance_Config_Trait;
    /**
     * Default config for this class
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['levels' => [], 'scopes' => [], 'formatter' => Default_Formatter::class];
    protected Abstract_Formatter $formatter;
    /**
     * __construct method
     *
     * @param array<string, mixed> $config Configuration array
     */
    public function __construct(array $config = [])
    {
        $this->set_config($config);
        // Backwards compatibility shim as we can't deprecate using false because of how 4.x merges configuration.
        if ($this->_config['scopes'] === false) {
            deprecation_warning('5.0.0', 'Using `false` to disable logging scopes is deprecated. Use `null` instead.');
            $this->_config['scopes'] = null;
        }
        if ($this->_config['scopes'] !== null) {
            $this->_config['scopes'] = (array) $this->_config['scopes'];
        }
        $this->_config['levels'] = (array) $this->_config['levels'];
        if (!empty($this->_config['types']) && empty($this->_config['levels'])) {
            $this->_config['levels'] = (array) $this->_config['types'];
        }
        /** @var \Cake\Log\Formatter\AbstractFormatter|array|class-string<\Cake\Log\Formatter\AbstractFormatter> $formatter */
        $formatter = $this->_config['formatter'] ?? Default_Formatter::class;
        if (!is_object($formatter)) {
            if (is_array($formatter)) {
                /** @var class-string<\Cake\Log\Formatter\AbstractFormatter> $class */
                $class = $formatter['className'];
                $options = $formatter;
            } else {
                $class = $formatter;
                $options = [];
            }
            $formatter = new $class($options);
        }
        $this->formatter = $formatter;
    }
    /**
     * Get the levels this logger is interested in.
     *
     * @return array<string>
     */
    public function levels(): array
    {
        return $this->_config['levels'];
    }
    /**
     * Get the scopes this logger is interested in.
     *
     * @return array<string>|null
     */
    public function scopes(): ?array
    {
        return $this->_config['scopes'];
    }
    /**
     * Replaces placeholders in message string with context values.
     *
     * @param \Stringable|string $message Formatted message.
     * @param array $context Context for placeholder values.
     */
    protected function interpolate(Stringable|string $message, array $context = []): string
    {
        $message = (string) $message;
        if (!str_contains($message, '{') && !str_contains($message, '}')) {
            return $message;
        }
        $found = preg_match_all('/(?<!\\\\)\{([a-z0-9-_]+)\}/i', $message, $matches);
        if ($found === false) {
            return $message;
        }
        $placeholders = array_intersect($matches[1], array_keys($context));
        $replacements = [];
        $json_flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;
        foreach ($placeholders as $key) {
            $value = $context[$key];
            if (is_scalar($value)) {
                $replacements['{' . $key . '}'] = (string) $value;
                continue;
            }
            if (is_array($value)) {
                $replacements['{' . $key . '}'] = json_encode($value, $json_flags);
                continue;
            }
            if ($value instanceof JsonSerializable) {
                $replacements['{' . $key . '}'] = json_encode($value, $json_flags);
                continue;
            }
            if ($value instanceof ArrayObject) {
                $replacements['{' . $key . '}'] = json_encode($value->get_array_copy(), $json_flags);
                continue;
            }
            if ($value instanceof Serializable) {
                $replacements['{' . $key . '}'] = $value->serialize();
                continue;
            }
            if (is_object($value)) {
                if (method_exists($value, 'toArray')) {
                    $replacements['{' . $key . '}'] = json_encode($value->to_array(), $json_flags);
                    continue;
                }
                if ($value instanceof Serializable) {
                    $replacements['{' . $key . '}'] = serialize($value);
                    continue;
                }
                if ($value instanceof Stringable) {
                    $replacements['{' . $key . '}'] = (string) $value;
                    continue;
                }
                if (method_exists($value, '__debugInfo')) {
                    $replacements['{' . $key . '}'] = json_encode($value->__debugInfo(), $json_flags);
                    continue;
                }
            }
            $replacements['{' . $key . '}'] = sprintf('[unhandled value of type %s]', get_debug_type($value));
        }
        return str_replace(array_keys($replacements), $replacements, $message);
    }
}