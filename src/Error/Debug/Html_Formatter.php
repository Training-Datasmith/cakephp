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

use function Cake\Core\h;
use InvalidArgumentException;
/**
 * A Debugger formatter for generating interactive styled HTML output.
 *
 * @internal
 */
class Html_Formatter implements Formatter_Interface
{
    protected static bool $output_header = false;
    /**
     * Random id so that HTML ids are not shared between dump outputs.
     */
    protected string $id;
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->id = uniqid('', true);
    }
    /**
     * Check if the current environment is not a CLI context
     */
    public static function environment_matches(): bool
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
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
            $line_info = sprintf('<span><strong>%s</strong> (line <strong>%s</strong>)</span>', $location['file'], $location['line']);
        }
        $parts = ['<div class="cake-debug-output cake-debug" style="direction:ltr">', $line_info, $contents, '</div>'];
        return implode("\n", $parts);
    }
    /**
     * Generate the CSS and Javascript for dumps
     *
     * Only output once per process as we don't need it more than once.
     */
    protected function dump_header(): string
    {
        ob_start();
        include __DIR__ . DIRECTORY_SEPARATOR . 'dumpHeader.html';
        return (string) ob_get_clean();
    }
    /**
     * Convert a tree of NodeInterface objects into HTML
     *
     * @param \Cake\Error\Debug\NodeInterface $node The node tree to dump.
     */
    public function dump(Node_Interface $node): string
    {
        $html = $this->export($node, 0);
        $head = '';
        if (!static::$output_header) {
            static::$output_header = true;
            $head = $this->dump_header();
        }
        return $head . '<div class="cake-debug">' . $html . '</div>';
    }
    /**
     * Convert a tree of NodeInterface objects into HTML
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
        $open = '<span class="cake-debug-array">' . $this->style('punct', '[') . '<samp class="cake-debug-array-items">';
        $vars = [];
        $break = "\n" . str_repeat('  ', $indent);
        $end_break = "\n" . str_repeat('  ', $indent - 1);
        $arrow = $this->style('punct', ' => ');
        foreach ($var->get_children() as $item) {
            $val = $item->get_value();
            $vars[] = $break . '<span class="cake-debug-array-item">' . $this->export($item->get_key(), $indent) . $arrow . $this->export($val, $indent) . $this->style('punct', ',') . '</span>';
        }
        $close = '</samp>' . $end_break . $this->style('punct', ']') . '</span>';
        return $open . implode('', $vars) . $close;
    }
    /**
     * Handles object to string conversion.
     *
     * @param \Cake\Error\Debug\ClassNode|\Cake\Error\Debug\ReferenceNode $var Object to convert.
     * @param int $indent The current indentation level.
     * @see \Cake\Error\Debugger::exportVar()
     */
    protected function export_object(Class_Node|Reference_Node $var, int $indent): string
    {
        $object_id = "cake-db-object-{$this->id}-{$var->get_id()}";
        $out = sprintf('<span class="cake-debug-object" id="%s">', $object_id);
        $break = "\n" . str_repeat('  ', $indent);
        $end_break = "\n" . str_repeat('  ', $indent - 1);
        if ($var instanceof Reference_Node) {
            $link = sprintf('<a class="cake-debug-ref" href="#%s">id: %s</a>', $object_id, $var->get_id());
            return '<span class="cake-debug-ref">' . $this->style('punct', 'object(') . $this->style('class', $var->get_value()) . $this->style('punct', ') ') . $link . $this->style('punct', ' {}') . '</span>';
        }
        $out .= $this->style('punct', 'object(') . $this->style('class', $var->get_value()) . $this->style('punct', ') id:') . $this->style('number', (string) $var->get_id()) . $this->style('punct', ' {') . '<samp class="cake-debug-object-props">';
        $props = [];
        foreach ($var->get_children() as $property) {
            $arrow = $this->style('punct', ' => ');
            $visibility = $property->get_visibility();
            $name = $property->get_name();
            if ($visibility && $visibility !== 'public') {
                $props[] = $break . '<span class="cake-debug-prop">' . $this->style('visibility', $visibility) . ' ' . $this->style('property', $name) . $arrow . $this->export($property->get_value(), $indent) . '</span>';
            } else {
                $props[] = $break . '<span class="cake-debug-prop">' . $this->style('property', $name) . $arrow . $this->export($property->get_value(), $indent) . '</span>';
            }
        }
        $end = '</samp>' . $end_break . $this->style('punct', '}') . '</span>';
        if ($props !== []) {
            return $out . implode('', $props) . $end;
        }
        return $out . $end;
    }
    /**
     * Style text with HTML class names
     *
     * @param string $style The style name to use.
     * @param string $text The text to style.
     * @return string The styled output.
     */
    protected function style(string $style, string $text): string
    {
        return sprintf('<span class="cake-debug-%s">%s</span>', $style, h($text));
    }
}