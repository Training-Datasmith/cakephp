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
 * @since         2.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Mailer;

use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Instance_Config_Trait;
/**
 * Abstract transport for sending email
 */
abstract class Abstract_Transport
{
    use Instance_Config_Trait;
    /**
     * Default config for this class
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = [];
    /**
     * Send mail
     *
     * @param \Cake\Mailer\Message $message Email message.
     * @return array<string, mixed> Contains 'headers' and 'message' keys. Additional keys allowed.
     * @phpstan-return array{headers: string, message: string, ...}
     */
    abstract public function send(Message $message): array;
    /**
     * Constructor
     *
     * @param array<string, mixed> $config Configuration options.
     */
    public function __construct(array $config = [])
    {
        $this->set_config($config);
    }
    /**
     * Check that at least one destination header is set.
     *
     * @param \Cake\Mailer\Message $message Message instance.
     * @throws \Cake\Core\Exception\CakeException If at least one of to, cc or bcc is not specified.
     */
    protected function check_recipient(Message $message): void
    {
        if ($message->get_to() === [] && $message->get_cc() === [] && $message->get_bcc() === []) {
            throw new Cake_Exception('You must specify at least one recipient.' . ' Use one of `setTo`, `setCc` or `setBcc` to define a recipient.');
        }
    }
}