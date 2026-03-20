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
 * @since         4.4.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Error\Renderer;

use Cake\Error\Exception_Renderer_Interface;
use Psr\Http\Message\Response_Interface;
use Throwable;
/**
 * Plain text exception rendering with a stack trace.
 *
 * Useful in CI or plain text environments.
 */
class Text_Exception_Renderer implements Exception_Renderer_Interface
{
    /**
     * Constructor.
     *
     * @param \Throwable $error The error to render.
     */
    public function __construct(protected Throwable $error)
    {
    }
    /**
     * Render an exception into a plain text message.
     */
    public function render(): Response_Interface|string
    {
        return sprintf("%s : %s on line %s of %s\nTrace:\n%s", $this->error->get_code(), $this->error->get_message(), $this->error->get_line(), $this->error->get_file(), $this->error->get_trace_as_string());
    }
    /**
     * Write output to stdout.
     *
     * @param \Psr\Http\Message\ResponseInterface|string $output The output to print.
     */
    public function write(Response_Interface|string $output): void
    {
        assert(is_string($output));
        echo $output;
    }
}