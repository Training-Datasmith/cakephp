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
namespace Cake\Controller\Exception;

use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Exception\Http_Error_Code_Interface;
/**
 * Missing Action exception - used when a controller action
 * cannot be found, or when the controller's isAction() method returns false.
 */
class Missing_Action_Exception extends Cake_Exception implements Http_Error_Code_Interface
{
    /**
     * @inheritDoc
     */
    protected int $_default_code = 404;
    /**
     * @inheritDoc
     */
    protected string $_message_template = 'Action `%s::%s()` could not be found, or is not accessible.';
}