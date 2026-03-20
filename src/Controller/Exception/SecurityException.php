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

use function Cake\Core\Deprecation_Warning;
use Cake\Http\Exception\Bad_Request_Exception;
use Throwable;
/**
 * Security exception - used when SecurityComponent detects any issue with the current request
 *
 * @deprecated 5.2.0 This exception is no longer used in the CakePHP core.
 */
class Security_Exception extends Bad_Request_Exception
{
    /**
     * Security Exception type
     */
    protected string $_type = 'secure';
    /**
     * Reason for request blackhole
     */
    protected ?string $_reason = null;
    /**
     * Constructor
     *
     * @param string|null $message If no message is given 'Bad Request' will be the message
     * @param int|null $code Status code, defaults to 400
     * @param \Throwable|null $previous The previous exception.
     */
    public function __construct(?string $message = null, ?int $code = null, ?Throwable $previous = null)
    {
        deprecation_warning('5.2.0', static::class . ' is deprecated. Use BadRequestException or a custom exception instead.');
        parent::__construct($message, $code, $previous);
    }
    /**
     * Getter for type
     */
    public function get_type(): string
    {
        return $this->_type;
    }
    /**
     * Set Message
     *
     * @param string $message Exception message
     */
    public function set_message(string $message): void
    {
        $this->message = $message;
    }
    /**
     * Set Reason
     *
     * @param string|null $reason Reason details
     * @return $this
     */
    public function set_reason(?string $reason = null): static
    {
        $this->_reason = $reason;
        return $this;
    }
    /**
     * Get Reason
     */
    public function get_reason(): ?string
    {
        return $this->_reason;
    }
}