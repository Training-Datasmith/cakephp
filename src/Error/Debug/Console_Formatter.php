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
 * @since         4.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Error\Debug;

use function Cake\Core\env;
use InvalidArgumentException;
/**
 * A Debugger formatter for generating output with ANSI escape codes
 *
 * @internal
 */
class Console_Formatter implements Formatter_Interface
{
    /**
     * text colors used in colored output.
     *
     * @var array<string, string>
     */
    protected array $styles = [
        // bold yellow
        'const' => '1;33',
        // green
        'string' => '0;32',
        // bold blue
        'number' => '1;34',
        // cyan
        'class' => '0;36',
        // grey
        'punct' => '0;90',
        // default foreground
        'property' => '0;39',
        // magenta
        'visibility' => '0;35',
        // red
        'special' => '0;31',
    ];
    /**
     * Check if the current environment supports ANSI output.
     */
    public static function environment_matches(): bool
    {
        if (PHP_SAPI !== 'cli') {
            return false;
        }
        // NO_COLOR in environment means no color.
        if (env('NO_COLOR')) {
            return false;
        }
        // Windows environment checks
        if (DIRECTORY_SEPARATOR === '\\' && !str_contains(strtolower(php_uname('v')), 'windows 10') && !str_contains(strtolower((string) env('SHELL')), 'bash.exe') && !env('ANSICON') && env('ConEmuANSI') !== 'ON') {
            return false;
        }
        return true;
    }
    /**
     * @inheritDoc
     */
    public function format_wrapper(string $contents, array $location): string
    {
        $line_info = '';
        if (isset($location['file'], $location['line'])) {
            $line_info = sprintf('%s (line %s)', $location['file'], $location['line']);
        }
        $parts = [$this->style('const', $line_info), $this->style('special', '########## DEBUG ##########'), $contents, $this->style('special', '###########################'), ''];
        return implode("\n", $parts);
    }
    /**
     * Convert a tree of NodeInterface objects into a plain text string.
     *
     * @param \Cake\Error\Debug\NodeInterface $node The node tree to dump.
     */
    public function dump(Node_Interface $node): string
    {
        $indent = 0;
        return $this->export($node, $indent);
    }
    /**
     * Convert a tree of NodeInterface objects into a plain text string.
     *
     * @param \Cake\Error\Debug\NodeInterface $var The node tree to dump.
     * @param int $indent The current indentation level.
     */
    protected function export(Node_Interface $var, int $indent): string
    {
        if ($var instanceof Scalar_Node) {
            return match ($var->get_type()) {
                'bool' => $this->style('const', $var->get_value() ? 'true' : 'false'),
                'null' => $this->style('const', 'null'),
                'string' => $this->style('string', "'" . $var->get_value() . "'"),
                'int', 'float' => $this->style('visibility', "({$var->get_type()})") . ' ' . $this->style('number', "{$var->get_value()}"),
                default => "({$var->get_type()}) {$var->get_value()}",
            };
        }
        if ($var instanceof Array_Node) {
            return $this->export_array($var, $indent + 1);
        }
        if ($var instanceof Class_Node || $var instanceof Reference_Node) {
            return $this->export_object($var, $indent + 1);
        }
        if ($var instanceof Special_Node) {
            return $this->style('special', $var->get_value());
        }
        throw new InvalidArgumentException('Unknown node received ' . $var::class);
    }
    /**
     * Export an array type object
     *
     * @param \Cake\Error\Debug\ArrayNode $var The array to export.
     * @param int $indent The current indentation level.
     * @return string Exported array.
     */
    protected function export_array(Array_Node $var, int $indent): string
    {
        $out = $this->style('punct', '[');
        $break = "\n" . str_repeat('  ', $indent);
        $end = "\n" . str_repeat('  ', $indent - 1);
        $vars = [];
        $arrow = $this->style('punct', ' => ');
        foreach ($var->get_children() as $item) {
            $val = $item->get_value();
            $vars[] = $break . $this->export($item->get_key(), $indent) . $arrow . $this->export($val, $indent);
        }
        $close = $this->style('punct', ']');
        if ($vars !== []) {
            return $out . implode($this->style('punct', ','), $vars) . $end . $close;
        }
        return $out . $close;
    }
    /**
     * Handles object to string conversion.
     *
     * @param \Cake\Error\Debug\ClassNode|\Cake\Error\Debug\ReferenceNode $var Object to convert.
     * @param int $indent Current indentation level.
     * @see \Cake\Error\Debugger::exportVar()
     */
    protected function export_object(Class_Node|Reference_Node $var, int $indent): string
    {
        $props = [];
        if ($var instanceof Reference_Node) {
            return $this->style('punct', 'object(') . $this->style('class', $var->get_value()) . $this->style('punct', ') id:') . $this->style('number', (string) $var->get_id()) . $this->style('punct', ' {}');
        }
        $out = $this->style('punct', 'object(') . $this->style('class', $var->get_value()) . $this->style('punct', ') id:') . $this->style('number', (string) $var->get_id()) . $this->style('punct', ' {');
        $break = "\n" . str_repeat('  ', $indent);
        $end = "\n" . str_repeat('  ', $indent - 1) . $this->style('punct', '}');
        $arrow = $this->style('punct', ' => ');
        foreach ($var->get_children() as $property) {
            $visibility = $property->get_visibility();
            $name = $property->get_name();
            if ($visibility && $visibility !== 'public') {
                $props[] = $this->style('visibility', $visibility) . ' ' . $this->style('property', $name) . $arrow . $this->export($property->get_value(), $indent);
            } else {
                $props[] = $this->style('property', $name) . $arrow . $this->export($property->get_value(), $indent);
            }
        }
        if ($props !== []) {
            return $out . $break . implode($break, $props) . $end;
        }
        return $out . $this->style('punct', '}');
    }
    /**
     * Style text with ANSI escape codes.
     *
     * @param string $style The style name to use.
     * @param string $text The text to style.
     * @return string The styled output.
     */
    protected function style(string $style, string $text): string
    {
        $code = $this->styles[$style];
        return "\x1b[{$code}m{$text}\x1b[0m";
    }
}