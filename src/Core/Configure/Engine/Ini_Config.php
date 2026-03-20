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
namespace Cake\Core\Configure\Engine;

use Cake\Core\Configure\Config_Engine_Interface;
use Cake\Core\Configure\File_Config_Trait;
use Cake\Core\Exception\Cake_Exception;
use Cake\Utility\Hash;
/**
 * Ini file configuration engine.
 *
 * Since IniConfig uses parse_ini_file underneath, you should be aware that this
 * class shares the same behavior, especially with regards to boolean and null values.
 *
 * In addition to the native `parse_ini_file` features, IniConfig also allows you
 * to create nested array structures through usage of `.` delimited names. This allows
 * you to create nested arrays structures in an ini config file. For example:
 *
 * `db.password = secret` would turn into `['db' => ['password' => 'secret']]`
 *
 * You can nest properties as deeply as needed using `.`'s. In addition to using `.` you
 * can use standard ini section notation to create nested structures:
 *
 * ```
 * [section]
 * key = value
 * ```
 *
 * Once loaded into Configure, the above would be accessed using:
 *
 * `Configure::read('section.key');`
 *
 * You can also use `.` separated values in section names to create more deeply
 * nested structures.
 *
 * IniConfig also manipulates how the special ini values of
 * 'yes', 'no', 'on', 'off', 'null' are handled. These values will be
 * converted to their boolean equivalents.
 *
 * @see https://secure.php.net/parse_ini_file
 */
class Ini_Config implements Config_Engine_Interface
{
    use File_Config_Trait;
    /**
     * File extension.
     */
    protected string $_extension = '.ini';
    /**
     * Build and construct a new ini file parser. The parser can be used to read
     * ini files that are on the filesystem.
     *
     * @param string|null $path Path to load ini config files from. Defaults to CONFIG.
     * @param string|null $_section Only get one section, leave null to parse and fetch
     *     all sections in the ini file.
     */
    public function __construct(
        ?string $path = null,
        /**
         * The section to read, if null all sections will be read.
         */
        protected ?string $_section = null
    )
    {
        $this->_path = $path ?? CONFIG;
    }
    /**
     * Read an ini file and return the results as an array.
     *
     * @param string $key The identifier to read from. If the key has a . it will be treated
     *  as a plugin prefix. The chosen file must be on the engine's path.
     * @return array Parsed configuration values.
     * @throws \Cake\Core\Exception\CakeException when files don't exist.
     *  Or when files contain '..' as this could lead to abusive reads.
     */
    public function read(string $key): array
    {
        $file = $this->_get_file_path($key, true);
        $contents = parse_ini_file($file, true);
        if ($contents === false) {
            throw new Cake_Exception(sprintf('Cannot parse INI file `%s`', $file));
        }
        if ($this->_section && isset($contents[$this->_section])) {
            $values = $this->_parse_nested_values($contents[$this->_section]);
        } else {
            $values = [];
            foreach ($contents as $section => $attribs) {
                if (is_array($attribs)) {
                    $values[$section] = $this->_parse_nested_values($attribs);
                } else {
                    $parse = $this->_parse_nested_values([$attribs]);
                    $values[$section] = array_shift($parse);
                }
            }
        }
        return $values;
    }
    /**
     * parses nested values out of keys.
     *
     * @param array $values Values to be exploded.
     * @return array Array of values exploded
     */
    protected function _parse_nested_values(array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value === '1') {
                $value = true;
            }
            if ($value === '') {
                $value = false;
            }
            unset($values[$key]);
            if (str_contains((string) $key, '.')) {
                $values = Hash::insert($values, $key, $value);
            } else {
                $values[$key] = $value;
            }
        }
        return $values;
    }
    /**
     * Dumps the state of Configure data into an ini formatted string.
     *
     * @param string $key The identifier to write to. If the key has a . it will be treated
     *  as a plugin prefix.
     * @param array $data The data to convert to ini file.
     * @return bool Success.
     */
    public function dump(string $key, array $data): bool
    {
        $result = [];
        foreach ($data as $k => $value) {
            $is_section = false;
            if (!str_starts_with((string) $k, '[')) {
                $result[] = "[{$k}]";
                $is_section = true;
            }
            if (is_array($value)) {
                $k_values = Hash::flatten($value, '.');
                foreach ($k_values as $k2 => $v) {
                    $result[] = "{$k2} = " . $this->_value($v);
                }
            }
            if ($is_section) {
                $result[] = '';
            }
        }
        $contents = trim(implode("\n", $result));
        $filename = $this->_get_file_path($key);
        return file_put_contents($filename, $contents) > 0;
    }
    /**
     * Converts a value into the ini equivalent
     *
     * @param mixed $value Value to export.
     * @return string String value for ini file.
     */
    protected function _value(mixed $value): string
    {
        return match ($value) {
            null => 'null',
            true => 'true',
            false => 'false',
            default => (string) $value,
        };
    }
}