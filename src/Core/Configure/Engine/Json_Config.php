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
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Core\Configure\Engine;

use Cake\Core\Configure\Config_Engine_Interface;
use Cake\Core\Configure\File_Config_Trait;
use Cake\Core\Exception\Cake_Exception;
/**
 * JSON engine allows Configure to load configuration values from
 * files containing JSON strings.
 *
 * An example JSON file would look like::
 *
 * ```
 * {
 *     "debug": false,
 *     "App": {
 *         "namespace": "MyApp"
 *     },
 *     "Security": {
 *         "salt": "its-secret"
 *     }
 * }
 * ```
 */
class Json_Config implements Config_Engine_Interface
{
    use File_Config_Trait;
    /**
     * File extension.
     */
    protected string $_extension = '.json';
    /**
     * Constructor for JSON Config file reading.
     *
     * @param string|null $path The path to read config files from. Defaults to CONFIG.
     */
    public function __construct(?string $path = null)
    {
        $this->_path = $path ?? CONFIG;
    }
    /**
     * Read a config file and return its contents.
     *
     * Files with `.` in the name will be treated as values in plugins. Instead of
     * reading from the initialized path, plugin keys will be located using Plugin::path().
     *
     * @param string $key The identifier to read from. If the key has a . it will be treated
     *   as a plugin prefix.
     * @return array Parsed configuration values.
     * @throws \Cake\Core\Exception\CakeException When files don't exist or when
     *   files contain '..' (as this could lead to abusive reads) or when there
     *   is an error parsing the JSON string.
     */
    public function read(string $key): array
    {
        $file = $this->_get_file_path($key, true);
        $json_content = file_get_contents($file);
        if ($json_content === false) {
            throw new Cake_Exception(sprintf('Cannot read file content of `%s`', $file));
        }
        $values = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Cake_Exception(sprintf('Error parsing JSON string fetched from config file `%s.json`: %s', $key, json_last_error_msg()));
        }
        if (!is_array($values)) {
            throw new Cake_Exception(sprintf('Decoding JSON config file `%s.json` did not return an array', $key));
        }
        return $values;
    }
    /**
     * Converts the provided $data into a JSON string that can be used saved
     * into a file and loaded later.
     *
     * @param string $key The identifier to write to. If the key has a . it will
     *  be treated as a plugin prefix.
     * @param array $data Data to dump.
     * @return bool Success
     */
    public function dump(string $key, array $data): bool
    {
        $filename = $this->_get_file_path($key);
        return file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT)) !== false;
    }
}