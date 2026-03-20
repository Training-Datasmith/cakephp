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
 * @link          https://cakephp.org CakePHP Project
 * @since         5.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command\Helper;

use Backed_Enum;
use Cake\Console\Helper;
use Cake\Database\Type\Enum_Label_Interface;
use Closure;
use Unit_Enum;
/**
 * Tree command helper.
 *
 * Formats nested arrays into a tree with dashes and pipes.
 */
class Tree_Helper extends Helper
{
    /**
     * @inheritDoc
     */
    protected array $_default_config = ['baseIndent' => 0, 'elementIndent' => 0];
    /**
     * Outputs an array in tree form.
     *
     * @param array $args Tree array
     */
    public function output(array $args): void
    {
        $prefix = str_repeat(' ', $this->_config['baseIndent']);
        $this->output_array($args, $prefix, topLevel: true);
    }
    /**
     * Output an array in a tree.
     */
    protected function output_array(array $array, string $prefix, bool $top_level): void
    {
        $i = 1;
        $num_values = count($array);
        $element_prefix = $top_level ? '' : str_repeat(' ', $this->_config['elementIndent']);
        foreach ($array as $key => $value) {
            $is_last = $i++ === $num_values;
            $marker = $is_last ? '└── ' : '├── ';
            $indent = $is_last ? '    ' : '│   ';
            $this->output_element($key, $value, $prefix . $element_prefix, $marker, $indent);
        }
    }
    /**
     * Output an array element.
     */
    protected function output_element(string|int $key, mixed $value, string $prefix, string $marker, string $indent): void
    {
        if (is_array($value)) {
            $this->_io->out($prefix . $marker . $key);
            $this->output_array($value, $prefix . $indent, topLevel: false);
        } elseif (is_string($key)) {
            $this->_io->out($prefix . $marker . $key);
            $this->output_value($value, $prefix . $indent . '└── ');
        } else {
            $this->output_value($value, $prefix . $marker);
        }
    }
    /**
     * Output a value in a tree.
     */
    protected function output_value(mixed $value, string $prefix): void
    {
        if ($value instanceof Closure) {
            $this->_io->out($prefix . $value());
        } elseif ($value instanceof Enum_Label_Interface) {
            $this->_io->out($prefix . $value->label());
        } elseif ($value instanceof Backed_Enum) {
            $this->_io->out($prefix . $value->value);
        } elseif ($value instanceof Unit_Enum) {
            $this->_io->out($prefix . $value->name);
        } elseif (is_bool($value)) {
            $this->_io->out($prefix . ($value ? 'true' : 'false'));
        } else {
            $this->_io->out($prefix . $value);
        }
    }
}