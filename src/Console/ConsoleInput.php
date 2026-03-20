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
/**
 * Object wrapper for interacting with stdin
 */
class Console_Input
{
    /**
     * Input value.
     *
     * @var resource
     */
    protected $_input;
    /**
     * Can this instance use readline?
     * Two conditions must be met:
     * 1. Readline support must be enabled.
     * 2. Handle we are attached to must be stdin.
     * Allows rich editing with arrow keys and history when inputting a string.
     */
    protected bool $_can_readline;
    /**
     * Constructor
     *
     * @param string $handle The location of the stream to use as input.
     */
    public function __construct(string $handle = 'php://stdin')
    {
        $this->_can_readline = extension_loaded('readline') && $handle === 'php://stdin';
        $input = fopen($handle, 'rb');
        if ($input === false) {
            throw new Cake_Exception(sprintf('Cannot open handle `%s`', $handle));
        }
        $this->_input = $input;
    }
    /**
     * Destruct and free resources
     */
    public function __destruct()
    {
        // @phpstan-ignore isset.property (property may not be set if constructor throws)
        if (isset($this->_input) && is_resource($this->_input)) {
            fclose($this->_input);
        }
        unset($this->_input);
    }
    /**
     * Read a value from the stream
     *
     * @return string|null The value of the stream. Null on EOF.
     */
    public function read(): ?string
    {
        if ($this->_can_readline) {
            $line = readline('');
            if ($line !== false && $line !== '') {
                readline_add_history($line);
            }
        } else {
            $line = fgets($this->_input);
        }
        if ($line === false) {
            return null;
        }
        return $line;
    }
    /**
     * Check if data is available on stdin
     *
     * @param int $timeout An optional time to wait for data
     * @return bool True for data available, false otherwise
     */
    public function data_available(int $timeout = 0): bool
    {
        $read_fds = [$this->_input];
        $write_fds = null;
        $error_fds = null;
        /** @var string|null $error */
        $error = null;
        set_error_handler(function (int $code, string $message) use (&$error): true {
            $error = "stream_select failed with code={$code} message={$message}.";
            return true;
        });
        $ready_fds = stream_select($read_fds, $write_fds, $error_fds, $timeout);
        restore_error_handler();
        if ($error !== null) {
            throw new Console_Exception($error);
        }
        return $ready_fds > 0;
    }
}