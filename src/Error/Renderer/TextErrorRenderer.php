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

use Cake\Error\Error_Renderer_Interface;
use Cake\Error\Php_Error;
/**
 * Plain text error rendering with a stack trace.
 *
 * Useful in CLI environments.
 */
class Text_Error_Renderer implements Error_Renderer_Interface
{
    /**
     * @inheritDoc
     */
    public function write(string $out): void
    {
        echo $out;
    }
    /**
     * @inheritDoc
     */
    public function render(Php_Error $error, bool $debug): string
    {
        if (!$debug) {
            return '';
        }
        return sprintf("%s: %s :: %s on line %s of %s\nTrace:\n%s", $error->get_label(), $error->get_code(), $error->get_message(), $error->get_line() ?? '', $error->get_file() ?? '', $error->get_trace_as_string());
    }
}