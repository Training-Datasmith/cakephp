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
 * @since         4.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Client\Exception;

use Cake\Core\Exception\Cake_Exception;
/**
 * Used to indicate that a request did not have a matching mock response.
 */
class Missing_Response_Exception extends Cake_Exception
{
    protected string $_message_template = 'Unable to find a mocked response for `%s` to `%s`.';
}