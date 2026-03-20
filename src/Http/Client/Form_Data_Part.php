<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Client;

use Cake\Utility\Text;
use Stringable;
/**
 * Contains the data and behavior for a single
 * part in a Multipart FormData request body.
 *
 * Added to Cake\Http\Client\FormData when sending
 * data to a remote server.
 *
 * @internal
 */
class Form_Data_Part implements Stringable
{
    /**
     * Content type to use
     */
    protected ?string $type = null;
    /**
     * Filename to send if using files.
     */
    protected ?string $filename = null;
    /**
     * The encoding used in this part.
     */
    protected ?string $transfer_encoding = null;
    /**
     * The contentId for the part
     */
    protected ?string $content_id = null;
    /**
     * Constructor
     *
     * @param string $name The name of the data.
     * @param string $value The value of the data.
     * @param string $disposition The type of disposition to use, defaults to form-data.
     * @param string|null $charset The charset of the data.
     */
    public function __construct(protected string $name, protected string $value, protected string $disposition = 'form-data', protected ?string $charset = null)
    {
    }
    /**
     * Get/set the disposition type
     *
     * By passing in `false` you can disable the disposition
     * header from being added.
     *
     * @param string|null $disposition Use null to get/string to set.
     */
    public function disposition(?string $disposition = null): string
    {
        if ($disposition === null) {
            return $this->disposition;
        }
        return $this->disposition = $disposition;
    }
    /**
     * Get/set the contentId for a part.
     *
     * @param string|null $id The content id.
     */
    public function content_id(?string $id = null): ?string
    {
        if ($id === null) {
            return $this->content_id;
        }
        return $this->content_id = $id;
    }
    /**
     * Get/set the filename.
     *
     * Setting the filename to `false` will exclude it from the
     * generated output.
     *
     * @param string|null $filename Use null to get/string to set.
     */
    public function filename(?string $filename = null): ?string
    {
        if ($filename === null) {
            return $this->filename;
        }
        return $this->filename = $filename;
    }
    /**
     * Get/set the content type.
     *
     * @param string|null $type Use null to get/string to set.
     */
    public function type(?string $type): ?string
    {
        if ($type === null) {
            return $this->type;
        }
        return $this->type = $type;
    }
    /**
     * Set the transfer-encoding for multipart.
     *
     * Useful when content bodies are in encodings like base64.
     *
     * @param string|null $type The type of encoding the value has.
     */
    public function transfer_encoding(?string $type): ?string
    {
        if ($type === null) {
            return $this->transfer_encoding;
        }
        return $this->transfer_encoding = $type;
    }
    /**
     * Get the part name.
     */
    public function name(): string
    {
        return $this->name;
    }
    /**
     * Get the value.
     */
    public function value(): string
    {
        return $this->value;
    }
    /**
     * Convert the part into a string.
     *
     * Creates a string suitable for use in HTTP requests.
     */
    public function __toString(): string
    {
        $out = '';
        if ($this->disposition) {
            $out .= 'Content-Disposition: ' . $this->disposition;
            if ($this->name) {
                $out .= '; ' . $this->_header_parameter_to_string('name', $this->name);
            }
            if ($this->filename) {
                $out .= '; ' . $this->_header_parameter_to_string('filename', $this->filename);
            }
            $out .= "\r\n";
        }
        if ($this->type) {
            $out .= 'Content-Type: ' . $this->type . "\r\n";
        }
        if ($this->transfer_encoding) {
            $out .= 'Content-Transfer-Encoding: ' . $this->transfer_encoding . "\r\n";
        }
        if ($this->content_id) {
            $out .= 'Content-ID: <' . $this->content_id . ">\r\n";
        }
        $out .= "\r\n";
        return $out . $this->value;
    }
    /**
     * Get the string for the header parameter.
     *
     * If the value contains non-ASCII letters an additional header indicating
     * the charset encoding will be set.
     *
     * @param string $name The name of the header parameter
     * @param string $value The value of the header parameter
     */
    protected function _header_parameter_to_string(string $name, string $value): string
    {
        $transliterated = Text::transliterate(str_replace('"', '', $value));
        $return = sprintf('%s="%s"', $name, $transliterated);
        if ($this->charset !== null && $value !== $transliterated) {
            $return .= sprintf("; %s*=%s''%s", $name, strtolower($this->charset), rawurlencode($value));
        }
        return $return;
    }
}