<?php

declare (strict_types=1);
/**
 * Send mail using mail() function
 *
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
namespace Cake\Mailer\Transport;

use Cake\Core\Exception\Cake_Exception;
use Cake\Mailer\Abstract_Transport;
use Cake\Mailer\Message;
/**
 * Send mail using mail() function
 */
class Mail_Transport extends Abstract_Transport
{
    /**
     * @inheritDoc
     */
    public function send(Message $message): array
    {
        $this->check_recipient($message);
        // https://github.com/cakephp/cakephp/issues/2209
        // https://bugs.php.net/bug.php?id=47983
        $subject = str_replace("\r\n", '', $message->get_subject());
        $to = $message->get_headers(['to'])['To'];
        $to = str_replace("\r\n", '', $to);
        $eol = $this->get_config('eol', "\r\n");
        $headers = $message->get_headers_string(['from', 'sender', 'replyTo', 'readReceipt', 'returnPath', 'cc', 'bcc'], $eol, fn($val) => str_replace("\r\n", '', $val));
        $message = $message->get_body_string($eol);
        $params = $this->get_config('additionalParameters', '');
        $this->_mail($to, $subject, $message, $headers, $params);
        $headers .= $eol . 'To: ' . $to;
        $headers .= $eol . 'Subject: ' . $subject;
        return ['headers' => $headers, 'message' => $message];
    }
    /**
     * Wraps internal function mail() and throws exception instead of errors if anything goes wrong
     *
     * @param string $to email's recipient
     * @param string $subject email's subject
     * @param string $message email's body
     * @param string $headers email's custom headers
     * @param string $params additional params for sending email
     * @throws \Cake\Network\Exception\SocketException if mail could not be sent
     */
    protected function _mail(string $to, string $subject, string $message, string $headers = '', string $params = ''): void
    {
        // phpcs:disable
        if (!@mail($to, $subject, $message, $headers, $params)) {
            $error = error_get_last();
            $msg = 'Could not send email: ' . ($error['message'] ?? 'unknown');
            throw new Cake_Exception($msg);
        }
        // phpcs:enable
    }
}