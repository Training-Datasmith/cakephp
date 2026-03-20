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
 * @since         5.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Exception;

use Cake\Database\Log\Logged_Query;
use PDOException;
class Query_Exception extends PDOException
{
    /**
     * Constructor
     */
    public function __construct(protected Logged_Query|string $query, PDOException $previous)
    {
        $message = $previous->get_message();
        // Prefix with connection name if available
        $connection_name = $this->get_connection_name();
        if ($connection_name !== '') {
            $message = "[{$connection_name}] " . $message;
        }
        $message .= "\nQuery: " . $this->get_query_string();
        parent::__construct($message, (int) $previous->get_code(), $previous);
    }
    /**
     * Get the connection name that caused this exception.
     */
    public function get_connection_name(): string
    {
        if ($this->query instanceof Logged_Query) {
            return $this->query->get_connection_name();
        }
        return '';
    }
    /**
     * Get the query string that caused this exception.
     */
    public function get_query_string(): string
    {
        if ($this->query instanceof Logged_Query) {
            return (string) $this->query;
        }
        return $this->query;
    }
}