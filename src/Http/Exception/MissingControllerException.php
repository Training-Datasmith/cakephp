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
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Exception;

use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Exception\Http_Error_Code_Interface;
/**
 * Missing Controller exception - used when a controller
 * cannot be found.
 */
class Missing_Controller_Exception extends Cake_Exception implements Http_Error_Code_Interface
{
    /**
     * @inheritDoc
     */
    protected int $_default_code = 404;
    /**
     * @inheritDoc
     */
    protected string $_message_template = 'Controller class `%s` could not be found.';
}