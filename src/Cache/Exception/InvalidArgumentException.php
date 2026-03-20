<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.7.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Cache\Exception;

use Cake\Core\Exception\Cake_Exception;
use Psr\Simple_Cache\InvalidArgumentException as InvalidArgumentInterface;
/**
 * Exception raised when cache keys are invalid.
 */
class InvalidArgumentException extends Cake_Exception implements Invalid_Argument_Interface
{
}