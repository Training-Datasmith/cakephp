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
 * @since         3.5.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource\Paging\Exception;

use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Exception\Http_Error_Code_Interface;
/**
 * Exception raised when requested page number does not exist.
 */
class Page_Out_Of_Bounds_Exception extends Cake_Exception implements Http_Error_Code_Interface
{
    /**
     * @inheritDoc
     */
    protected int $_default_code = 404;
    /**
     * @inheritDoc
     */
    protected string $_message_template = 'Page number `%s` could not be found.';
}