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
namespace Cake\I18n\Parser;

use Cake\Core\Exception\Cake_Exception;
/**
 * Parses file in MO format
 *
 * @copyright Copyright (c) 2010, Union of RAD http://union-of-rad.org (http://lithify.me/)
 * @copyright Copyright (c) 2014, Fabien Potencier https://github.com/symfony/Translation/blob/master/LICENSE
 */
class Mo_File_Parser
{
    /**
     * Magic used for validating the format of a MO file as well as
     * detecting if the machine used to create that file was little endian.
     *
     * @var int
     */
    public const MO_LITTLE_ENDIAN_MAGIC = 0x950412de;
    /**
     * Magic used for validating the format of a MO file as well as
     * detecting if the machine used to create that file was big endian.
     *
     * @var int
     */
    public const MO_BIG_ENDIAN_MAGIC = 0xde120495;
    /**
     * The size of the header of a MO file in bytes.
     *
     * @var int
     */
    public const MO_HEADER_SIZE = 28;
    /**
     * Parses machine object (MO) format, independent of the machine's endian it
     * was created on. Both 32bit and 64bit systems are supported.
     *
     * @param string $file The file to be parsed.
     * @return array List of messages extracted from the file
     * @throws \Cake\Core\Exception\CakeException If stream content has an invalid format.
     */
    public function parse(string $file): array
    {
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            throw new Cake_Exception(sprintf('Cannot open resource `%s`', $file));
        }
        $stat = fstat($stream);
        if ($stat === false || $stat['size'] < self::MO_HEADER_SIZE) {
            throw new Cake_Exception('Invalid format for MO translations file');
        }
        /** @var array $magic */
        $magic = unpack('V1', (string) fread($stream, 4));
        $magic = hexdec(substr(dechex(current($magic)), -8));
        if ($magic === self::MO_LITTLE_ENDIAN_MAGIC) {
            $is_big_endian = false;
        } elseif ($magic === self::MO_BIG_ENDIAN_MAGIC) {
            $is_big_endian = true;
        } else {
            throw new Cake_Exception('Invalid format for MO translations file');
        }
        // offset formatRevision
        fread($stream, 4);
        $count = $this->_read_long($stream, $is_big_endian);
        $offset_id = $this->_read_long($stream, $is_big_endian);
        $offset_translated = $this->_read_long($stream, $is_big_endian);
        // Offset to start of translations
        fread($stream, 8);
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $plural_id = null;
            $context = null;
            $plurals = null;
            fseek($stream, $offset_id + $i * 8);
            $length = $this->_read_long($stream, $is_big_endian);
            $offset = $this->_read_long($stream, $is_big_endian);
            if ($length < 1) {
                continue;
            }
            fseek($stream, $offset);
            $singular_id = (string) fread($stream, $length);
            if (str_contains($singular_id, "\x04")) {
                [$context, $singular_id] = explode("\x04", $singular_id);
            }
            if (str_contains($singular_id, "\x00")) {
                [$singular_id, $plural_id] = explode("\x00", $singular_id);
            }
            fseek($stream, $offset_translated + $i * 8);
            $length = $this->_read_long($stream, $is_big_endian);
            if ($length < 1) {
                throw new Cake_Exception('Length must be > 0');
            }
            $offset = $this->_read_long($stream, $is_big_endian);
            fseek($stream, $offset);
            $translated = (string) fread($stream, $length);
            if ($plural_id !== null || str_contains($translated, "\x00")) {
                $translated = explode("\x00", $translated);
                $plurals = $plural_id !== null ? $translated : null;
                $translated = $translated[0];
            }
            $singular = $translated;
            if ($context !== null) {
                $messages[$singular_id]['_context'][$context] = $singular;
                if ($plural_id !== null) {
                    $messages[$plural_id]['_context'][$context] = $plurals;
                }
                continue;
            }
            $messages[$singular_id]['_context'][''] = $singular;
            if ($plural_id !== null) {
                $messages[$plural_id]['_context'][''] = $plurals;
            }
        }
        fclose($stream);
        return $messages;
    }
    /**
     * Reads an unsigned long from stream respecting endianness.
     *
     * @param resource $stream The File being read.
     * @param bool $isBigEndian Whether the current platform is Big Endian
     */
    protected function _read_long($stream, bool $is_big_endian): int
    {
        /** @var array $result */
        $result = unpack($is_big_endian ? 'N1' : 'V1', (string) fread($stream, 4));
        $result = current($result);
        return (int) substr((string) $result, -8);
    }
}