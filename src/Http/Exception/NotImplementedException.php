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

/**
 * Not Implemented Exception - used when an API method is not implemented
 */
class Not_Implemented_Exception extends Http_Exception
{
    /**
     * @inheritDoc
     */
    protected string $_message_template = '%s is not implemented.';
    /**
     * @inheritDoc
     */
    protected int $_default_code = 501;
}