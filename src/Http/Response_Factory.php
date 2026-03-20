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

use Psr\Http\Message\Response_Factory_Interface;
use Psr\Http\Message\Response_Interface;
/**
 * Factory class for creating response instances.
 */
class Response_Factory implements Response_Factory_Interface
{
    /**
     * Create a new response.
     *
     * @param int $code The HTTP status code. Defaults to 200.
     * @param string $reasonPhrase The reason phrase to associate with the status code
     *   in the generated response. If none is provided, implementations MAY use
     *   the defaults as suggested in the HTTP specification.
     */
    public function create_response(int $code = 200, string $reason_phrase = ''): Response_Interface
    {
        return (new Response())->with_status($code, $reason_phrase);
    }
}