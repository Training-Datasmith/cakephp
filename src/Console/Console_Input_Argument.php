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

use Cake\Console\Exception\Console_Exception;
use Cake\Core\Exception\Cake_Exception;
use Simple_Xml_Element;
/**
 * An object to represent a single argument used in the command line.
 * ConsoleOptionParser creates these when you use addArgument()
 *
 * @see \Cake\Console\ConsoleOptionParser::addArgument()
 */
class Console_Input_Argument
{
    /**
     * Name of the argument.
     */
    protected string $_name;
    /**
     * Help string
     */
    protected string $_help;
    /**
     * Is this option required?
     */
    protected bool $_required;
    /**
     * An array of valid choices for this argument.
     *
     * @var array<string>
     */
    protected array $_choices;
    /**
     * Default value for this argument.
     */
    protected ?string $_default = null;
    /**
     * The multiple separator.
     */
    protected ?string $_separator = null;
    /**
     * Make a new Input Argument
     *
     * @param array<string, mixed>|string $name The long name of the option, or an array with all the properties.
     * @param string $help The help text for this option
     * @param bool $required Whether this argument is required. Missing required args will trigger exceptions
     * @param array<string> $choices Valid choices for this option.
     * @param string|null $default The default value for this argument.
     */
    public function __construct(array|string $name, string $help = '', bool $required = false, array $choices = [], ?string $default = null, ?string $separator = null)
    {
        if (is_array($name)) {
            if (!isset($name['name'])) {
                throw new Cake_Exception('You must provide a `name` for the argument.');
            }
            foreach ($name as $key => $value) {
                $this->{'_' . $key} = $value;
            }
        } else {
            $this->_name = $name;
            $this->_help = $help;
            $this->_required = $required;
            $this->_choices = $choices;
            $this->_default = $default;
            $this->_separator = $separator;
        }
        if ($this->_separator !== null && str_contains($this->_separator, ' ')) {
            throw new Console_Exception(sprintf('The argument separator must not contain spaces for `%s`.', $this->_name));
        }
    }
    /**
     * Get the value of the name attribute.
     *
     * @return string Value of this->_name.
     */
    public function name(): string
    {
        return $this->_name;
    }
    /**
     * Checks if this argument is equal to another argument.
     *
     * @param \Cake\Console\ConsoleInputArgument $argument ConsoleInputArgument to compare to.
     */
    public function is_equal_to(Console_Input_Argument $argument): bool
    {
        return $this->name() === $argument->name() && $this->usage() === $argument->usage();
    }
    /**
     * Generate the help for this argument.
     *
     * @param int $width The width to make the name of the option.
     */
    public function help(int $width = 0): string
    {
        $name = $this->_name;
        if (strlen($name) < $width) {
            $name = str_pad($name, $width, ' ');
        }
        $optional = '';
        if (!$this->is_required()) {
            $optional = ' <comment>(optional)</comment>';
        }
        if ($this->_choices) {
            $optional .= sprintf(' <comment>(choices: %s)</comment>', implode('|', $this->_choices));
        }
        if ($this->_default !== null) {
            $optional .= sprintf(' <comment>default: "%s"</comment>', $this->_default);
        }
        if ($this->_separator) {
            $optional .= sprintf(' <comment>(separator: "%s")</comment>', $this->_separator);
        }
        return sprintf('%s%s%s', $name, $this->_help, $optional);
    }
    /**
     * Get the usage value for this argument
     */
    public function usage(): string
    {
        $name = $this->_name;
        if ($this->_choices) {
            $name = implode('|', $this->_choices);
        }
        $name = '<' . $name . '>';
        if (!$this->is_required()) {
            return '[' . $name . ']';
        }
        return $name;
    }
    /**
     * Get the default value for this argument
     */
    public function default_value(): ?string
    {
        return $this->_default;
    }
    /**
     * Check if this argument is a required argument
     */
    public function is_required(): bool
    {
        return $this->_required;
    }
    /**
     * Get the value of the separator.
     *
     * @return string|null Value of this->_separator.
     */
    public function separator(): ?string
    {
        return $this->_separator;
    }
    /**
     * Check that $value is a valid choice for this argument.
     *
     * @param string $value The choice to validate.
     * @return true
     * @throws \Cake\Console\Exception\ConsoleException
     */
    public function valid_choice(string $value): bool
    {
        if ($this->_choices === []) {
            return true;
        }
        if ($value && $this->_separator) {
            $values = explode($this->_separator, $value);
        } else {
            $values = [$value];
        }
        $unwanted = array_filter($values, fn(string $value): bool => !in_array($value, $this->_choices, true));
        if ($unwanted) {
            throw new Console_Exception(sprintf('`%s` is not a valid value for `%s`. Please use one of `%s`', $value, $this->_name, implode('|', $this->_choices)));
        }
        return true;
    }
    /**
     * Append this arguments XML representation to the passed in SimpleXml object.
     *
     * @param \SimpleXMLElement $parent The parent element.
     * @return \SimpleXMLElement The parent with this argument appended.
     */
    public function xml(Simple_Xml_Element $parent): Simple_Xml_Element
    {
        $option = $parent->add_child('argument');
        assert($option !== null);
        $option->add_attribute('name', $this->_name);
        $option->add_attribute('help', $this->_help);
        $option->add_attribute('required', (string) (int) $this->is_required());
        if ($this->separator() !== null) {
            $option->add_attribute('separator', $this->separator());
        }
        $choices = $option->add_child('choices');
        foreach ($this->_choices as $valid) {
            $choices->add_child('choice', $valid);
        }
        if ($this->_default !== null) {
            $option->add_attribute('default', $this->_default);
        }
        return $parent;
    }
}