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
 * @since         5.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http;

use Laminas\Diactoros\Uploaded_File;
use Psr\Http\Message\Stream_Interface;
use Psr\Http\Message\Uploaded_File_Factory_Interface;
use Psr\Http\Message\Uploaded_File_Interface;
/**
 * Factory class for creating uploaded file instances.
 */
class Uploaded_File_Factory implements Uploaded_File_Factory_Interface
{
    /**
     * Create a new uploaded file.
     *
     * If a size is not provided it will be determined by checking the size of
     * the stream.
     *
     * @link http://php.net/manual/features.file-upload.post-method.php
     * @link http://php.net/manual/features.file-upload.errors.php
     * @param \Psr\Http\Message\StreamInterface $stream The underlying stream representing the
     *     uploaded file content.
     * @param int|null $size The size of the file in bytes.
     * @param int $error The PHP file upload error.
     * @param string|null $clientFilename The filename as provided by the client, if any.
     * @param string|null $clientMediaType The media type as provided by the client, if any.
     * @throws \InvalidArgumentException If the file resource is not readable.
     */
    public function create_uploaded_file(Stream_Interface $stream, ?int $size = null, int $error = UPLOAD_ERR_OK, ?string $client_filename = null, ?string $client_media_type = null): Uploaded_File_Interface
    {
        $size ??= $stream->get_size() ?? 0;
        return new Uploaded_File($stream, $size, $error, $client_filename, $client_media_type);
    }
}