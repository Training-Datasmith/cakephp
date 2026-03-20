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
 * @since         2.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Console;

use Cake\Utility\Text;
use Simple_Xml_Element;
/**
 * HelpFormatter formats help for console shells. Can format to either
 * text or XML formats. Uses ConsoleOptionParser methods to generate help.
 *
 * Generally not directly used. Using $parser->help($command, 'xml'); is usually
 * how you would access help. Or via the `--help=xml` option on the command line.
 *
 * Xml output is useful for integration with other tools like IDE's or other build tools.
 */
class Help_Formatter
{
    /**
     * The maximum number of arguments shown when generating usage.
     */
    protected int $_max_args = 6;
    /**
     * The maximum number of options shown when generating usage.
     */
    protected int $_max_options = 6;
    /**
     * Alias to display in the output.
     */
    protected string $_alias = 'cake';
    /**
     * Build the help formatter for an OptionParser
     *
     * @param \Cake\Console\ConsoleOptionParser $_parser The option parser help is being generated for.
     */
    public function __construct(
        /**
         * Option parser.
         */
        protected Console_Option_Parser $_parser
    )
    {
    }
    /**
     * Set the alias
     *
     * @param string $alias The alias
     */
    public function set_alias(string $alias): void
    {
        $this->_alias = $alias;
    }
    /**
     * Get the help as formatted text suitable for output on the command line.
     *
     * @param int $width The width of the help output.
     */
    public function text(int $width = 72): string
    {
        $parser = $this->_parser;
        $out = [];
        $description = $parser->get_description();
        if ($description) {
            $out[] = Text::wrap($description, $width);
            $out[] = '';
        }
        $out[] = '<info>Usage:</info>';
        $out[] = $this->_generate_usage();
        $out[] = '';
        $options = $parser->options();
        if ($options) {
            $max = $this->_get_max_length($options) + 8;
            $out[] = '<info>Options:</info>';
            $out[] = '';
            foreach ($options as $option) {
                $out[] = Text::wrap_block($option->help($max), ['width' => $width, 'indent' => str_repeat(' ', $max), 'indentAt' => 1]);
            }
            $out[] = '';
        }
        $arguments = $parser->arguments();
        if ($arguments) {
            $max = $this->_get_max_length($arguments) + 2;
            $out[] = '<info>Arguments:</info>';
            $out[] = '';
            foreach ($arguments as $argument) {
                $out[] = Text::wrap_block($argument->help($max), ['width' => $width, 'indent' => str_repeat(' ', $max), 'indentAt' => 1]);
            }
            $out[] = '';
        }
        $epilog = $parser->get_epilog();
        if ($epilog) {
            $out[] = Text::wrap($epilog, $width);
            $out[] = '';
        }
        return implode("\n", $out);
    }
    /**
     * Generate the usage for a shell based on its arguments and options.
     * Usage strings favor short options over the long ones. and optional args will
     * be indicated with []
     */
    protected function _generate_usage(): string
    {
        $usage = [$this->_alias . ' ' . $this->_parser->get_command()];
        $options = [];
        foreach ($this->_parser->options() as $option) {
            $options[] = $option->usage();
        }
        if (count($options) > $this->_max_options) {
            $options = ['[options]'];
        }
        $usage = array_merge($usage, $options);
        $args = [];
        foreach ($this->_parser->arguments() as $argument) {
            $args[] = $argument->usage();
        }
        if (count($args) > $this->_max_args) {
            $args = ['[arguments]'];
        }
        $usage = array_merge($usage, $args);
        return implode(' ', $usage);
    }
    /**
     * Iterate over a collection and find the longest named thing.
     *
     * @param array<\Cake\Console\ConsoleInputOption|\Cake\Console\ConsoleInputArgument> $collection The collection to find a max length of.
     */
    protected function _get_max_length(array $collection): int
    {
        $max = 0;
        foreach ($collection as $item) {
            $max = max(strlen($item->name()), $max);
        }
        return $max;
    }
    /**
     * Get the help as an XML string.
     *
     * @param bool $string Return the SimpleXml object or a string. Defaults to true.
     * @return \SimpleXMLElement|string See $string
     */
    public function xml(bool $string = true): Simple_Xml_Element|string
    {
        $parser = $this->_parser;
        $xml = new Simple_Xml_Element('<shell></shell>');
        $xml->add_child('command', $parser->get_command());
        $xml->add_child('description', $parser->get_description());
        $options = $xml->add_child('options');
        foreach ($parser->options() as $option) {
            $option->xml($options);
        }
        $arguments = $xml->add_child('arguments');
        foreach ($parser->arguments() as $argument) {
            $argument->xml($arguments);
        }
        $xml->add_child('epilog', $parser->get_epilog());
        return $string ? (string) $xml->as_xml() : $xml;
    }
}