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
namespace Cake\Mailer\Transport;

use function Cake\Core\env;
use Cake\Core\Exception\Cake_Exception;
use Cake\Mailer\Abstract_Transport;
use Cake\Mailer\Message;
use Cake\Network\Exception\Socket_Exception;
use Cake\Network\Socket;
use Exception;
/**
 * Send mail using SMTP protocol
 */
class Smtp_Transport extends Abstract_Transport
{
    public const AUTH_PLAIN = 'PLAIN';
    public const AUTH_LOGIN = 'LOGIN';
    public const AUTH_XOAUTH2 = 'XOAUTH2';
    public const SUPPORTED_AUTH_TYPES = [self::AUTH_PLAIN, self::AUTH_LOGIN, self::AUTH_XOAUTH2];
    /**
     * Default config for this class
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['host' => 'localhost', 'port' => 25, 'timeout' => 30, 'username' => null, 'password' => null, 'client' => null, 'tls' => false, 'keepAlive' => false, 'authType' => null];
    /**
     * Socket to SMTP server
     */
    protected Socket $_socket;
    /**
     * Content of email to return
     *
     * @var array<string, string>
     */
    protected array $_content = [];
    /**
     * The response of the last sent SMTP command.
     */
    protected array $_last_response = [];
    /**
     * Authentication type.
     */
    protected ?string $auth_type = null;
    /**
     * Destructor
     *
     * Tries to disconnect to ensure that the connection is being
     * terminated properly before the socket gets closed.
     */
    public function __destruct()
    {
        try {
            $this->disconnect();
        } catch (Exception) {
            // avoid fatal error on script termination
        }
    }
    /**
     * Returns only serializable properties
     *
     * @return array<string>
     */
    public function __serialize(): array
    {
        return array_diff_key(get_object_vars($this), ['_socket' => null]);
    }
    /**
     * Unserialize handler.
     *
     * Ensure that the socket property isn't reinitialized in a broken state.
     */
    public function __unserialize(array $data): void
    {
        unset($data['_socket']);
        foreach ($data as $key => $val) {
            $this->{$key} = $val;
        }
    }
    /**
     * Connect to the SMTP server.
     *
     * This method tries to connect only in case there is no open
     * connection available already.
     */
    public function connect(): void
    {
        if (!$this->connected()) {
            $this->_connect();
            $this->_auth();
        }
    }
    /**
     * Check whether an open connection to the SMTP server is available.
     */
    public function connected(): bool
    {
        return isset($this->_socket) && $this->_socket->is_connected();
    }
    /**
     * Disconnect from the SMTP server.
     *
     * This method tries to disconnect only in case there is an open
     * connection available.
     */
    public function disconnect(): void
    {
        if (!$this->connected()) {
            return;
        }
        $this->_disconnect();
    }
    /**
     * Returns the response of the last sent SMTP command.
     *
     * A response consists of one or more lines containing a response
     * code and an optional response message text:
     * ```
     * [
     *     [
     *         'code' => '250',
     *         'message' => 'mail.example.com'
     *     ],
     *     [
     *         'code' => '250',
     *         'message' => 'PIPELINING'
     *     ],
     *     [
     *         'code' => '250',
     *         'message' => '8BITMIME'
     *     ],
     *     // etc...
     * ]
     * ```
     */
    public function get_last_response(): array
    {
        return $this->_last_response;
    }
    /**
     * Send mail
     *
     * @param \Cake\Mailer\Message $message Message instance
     * @return array<string, mixed> Contains 'headers' and 'message' keys. Additional keys allowed.
     * @phpstan-return array{headers: string, message: string, ...}
     * @throws \Cake\Network\Exception\SocketException
     */
    public function send(Message $message): array
    {
        $this->check_recipient($message);
        if (!$this->connected()) {
            $this->_connect();
            $this->_auth();
        } else {
            $this->_smtp_send('RSET');
        }
        $this->_send_rcpt($message);
        $this->_send_data($message);
        if (!$this->_config['keepAlive']) {
            $this->_disconnect();
        }
        return $this->_content;
    }
    /**
     * Parses and stores the response lines in `'code' => 'message'` format.
     *
     * @param array<string> $responseLines Response lines to parse.
     */
    protected function _buffer_response_lines(array $response_lines): void
    {
        $response = [];
        foreach ($response_lines as $response_line) {
            if (preg_match('/^(\d{3})(?:[ -]+(.*))?$/', $response_line, $match)) {
                $response[] = ['code' => $match[1], 'message' => $match[2] ?? null];
            }
        }
        $this->_last_response = array_merge($this->_last_response, $response);
    }
    /**
     * Parses the last response line and extract the preferred authentication type.
     */
    protected function _parse_auth_type(): void
    {
        $auth_type = $this->get_config('authType');
        if ($auth_type !== null) {
            if (!in_array($auth_type, self::SUPPORTED_AUTH_TYPES)) {
                throw new Cake_Exception('Unsupported auth type. Available types are: ' . implode(', ', self::SUPPORTED_AUTH_TYPES));
            }
            $this->auth_type = $auth_type;
            return;
        }
        if (!isset($this->_config['username'], $this->_config['password'])) {
            return;
        }
        $auth = '';
        foreach ($this->_last_response as $line) {
            if ($line['message'] === '' || str_starts_with((string) $line['message'], 'AUTH ')) {
                $auth = $line['message'];
                break;
            }
        }
        if ($auth === '') {
            return;
        }
        foreach (self::SUPPORTED_AUTH_TYPES as $type) {
            if (str_contains((string) $auth, $type)) {
                $this->auth_type = $type;
                return;
            }
        }
        throw new Cake_Exception('Unsupported auth type: ' . substr((string) $auth, 5));
    }
    /**
     * Connect to SMTP Server
     *
     * @throws \Cake\Network\Exception\SocketException
     */
    protected function _connect(): void
    {
        $this->_generate_socket();
        if (!$this->_socket->connect()) {
            throw new Socket_Exception('Unable to connect to SMTP server.');
        }
        $this->_smtp_send(null, '220');
        $config = $this->_config;
        $host = 'localhost';
        if (isset($config['client'])) {
            if (empty($config['client'])) {
                throw new Socket_Exception('Cannot use an empty client name.');
            }
            $host = $config['client'];
        } else {
            $http_host = env('HTTP_HOST');
            if (is_string($http_host) && strlen($http_host)) {
                [$host] = explode(':', $http_host);
            }
        }
        try {
            $this->_smtp_send("EHLO {$host}", '250');
            if ($config['tls']) {
                $this->_smtp_send('STARTTLS', '220');
                $this->_socket->enable_crypto('tls');
                $this->_smtp_send("EHLO {$host}", '250');
            }
        } catch (Socket_Exception $e) {
            if ($config['tls']) {
                throw new Socket_Exception('SMTP server did not accept the connection or trying to connect to non TLS SMTP server using TLS.', null, $e);
            }
            try {
                $this->_smtp_send("HELO {$host}", '250');
            } catch (Socket_Exception $e2) {
                throw new Socket_Exception('SMTP server did not accept the connection.', null, $e2);
            }
        }
        $this->_parse_auth_type();
    }
    /**
     * Send authentication
     *
     * @throws \Cake\Network\Exception\SocketException
     */
    protected function _auth(): void
    {
        if (!isset($this->_config['username'], $this->_config['password'])) {
            return;
        }
        $username = $this->_config['username'];
        $password = $this->_config['password'];
        switch ($this->auth_type) {
            case self::AUTH_PLAIN:
                $this->_auth_plain($username, $password);
                break;
            case self::AUTH_LOGIN:
                $this->_auth_login($username, $password);
                break;
            case self::AUTH_XOAUTH2:
                $this->_auth_xoauth2($username, $password);
                break;
            default:
                $reply_code = $this->_auth_plain($username, $password);
                if ($reply_code === '235') {
                    break;
                }
                $this->_auth_login($username, $password);
        }
    }
    /**
     * Authenticate using AUTH PLAIN mechanism.
     *
     * @param string $username Username.
     * @param string $password Password.
     * @return string|null Response code for the command.
     */
    protected function _auth_plain(string $username, string $password): ?string
    {
        return $this->_smtp_send(sprintf('AUTH PLAIN %s', base64_encode(chr(0) . $username . chr(0) . $password)), '235|504|534|535');
    }
    /**
     * Authenticate using AUTH LOGIN mechanism.
     *
     * @param string $username Username.
     * @param string $password Password.
     */
    protected function _auth_login(string $username, string $password): void
    {
        $reply_code = $this->_smtp_send('AUTH LOGIN', '334|500|502|504');
        if ($reply_code === '334') {
            try {
                $this->_smtp_send(base64_encode($username), '334');
            } catch (Socket_Exception $e) {
                throw new Socket_Exception('SMTP server did not accept the username.', null, $e);
            }
            try {
                $this->_smtp_send(base64_encode($password), '235');
            } catch (Socket_Exception $e) {
                throw new Socket_Exception('SMTP server did not accept the password.', null, $e);
            }
        } elseif ($reply_code === '504') {
            throw new Socket_Exception('SMTP authentication method not allowed, check if SMTP server requires TLS.');
        } else {
            throw new Socket_Exception('AUTH command not recognized or not implemented, SMTP server may not require authentication.');
        }
    }
    /**
     * Authenticate using AUTH XOAUTH2 mechanism.
     *
     * @param string $username Username.
     * @param string $token Token.
     * @see https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth#smtp-protocol-exchange
     * @see https://developers.google.com/gmail/imap/xoauth2-protocol#smtp_protocol_exchange
     */
    protected function _auth_xoauth2(string $username, string $token): void
    {
        $auth_string = base64_encode(sprintf("user=%s\x01auth=Bearer %s\x01\x01", $username, $token));
        $this->_smtp_send('AUTH XOAUTH2 ' . $auth_string, '235');
    }
    /**
     * Prepares the `MAIL FROM` SMTP command.
     *
     * @param string $message The email address to send with the command.
     */
    protected function _prepare_from_cmd(string $message): string
    {
        return 'MAIL FROM:<' . $message . '>';
    }
    /**
     * Prepares the `RCPT TO` SMTP command.
     *
     * @param string $message The email address to send with the command.
     */
    protected function _prepare_rcpt_cmd(string $message): string
    {
        return 'RCPT TO:<' . $message . '>';
    }
    /**
     * Prepares the `from` email address.
     *
     * @param \Cake\Mailer\Message $message Message instance
     */
    protected function _prepare_from_address(Message $message): array
    {
        $from = $message->get_return_path();
        if (!$from) {
            return $message->get_from();
        }
        return $from;
    }
    /**
     * Prepares the recipient email addresses.
     *
     * @param \Cake\Mailer\Message $message Message instance
     */
    protected function _prepare_recipient_addresses(Message $message): array
    {
        $to = $message->get_to();
        $cc = $message->get_cc();
        $bcc = $message->get_bcc();
        return array_merge(array_keys($to), array_keys($cc), array_keys($bcc));
    }
    /**
     * Prepares the message body.
     *
     * @param \Cake\Mailer\Message $message Message instance
     */
    protected function _prepare_message(Message $message): string
    {
        $lines = $message->get_body();
        $messages = [];
        foreach ($lines as $line) {
            if (str_starts_with((string) $line, '.')) {
                $messages[] = '.' . $line;
            } else {
                $messages[] = $line;
            }
        }
        return implode("\r\n", $messages);
    }
    /**
     * Send emails
     *
     * @param \Cake\Mailer\Message $message Message instance
     * @throws \Cake\Network\Exception\SocketException
     */
    protected function _send_rcpt(Message $message): void
    {
        $from = $this->_prepare_from_address($message);
        $this->_smtp_send($this->_prepare_from_cmd((string) key($from)));
        $messages = $this->_prepare_recipient_addresses($message);
        foreach ($messages as $mail) {
            $this->_smtp_send($this->_prepare_rcpt_cmd($mail));
        }
    }
    /**
     * Send Data
     *
     * @param \Cake\Mailer\Message $message Message instance
     * @throws \Cake\Network\Exception\SocketException
     */
    protected function _send_data(Message $message): void
    {
        $this->_smtp_send('DATA', '354');
        $headers = $message->get_headers_string(['from', 'sender', 'replyTo', 'readReceipt', 'to', 'cc', 'subject', 'returnPath']);
        $message = $this->_prepare_message($message);
        $this->_smtp_send($headers . "\r\n\r\n" . $message . "\r\n\r\n\r\n.");
        $this->_content = ['headers' => $headers, 'message' => $message];
    }
    /**
     * Disconnect
     *
     * @throws \Cake\Network\Exception\SocketException
     */
    protected function _disconnect(): void
    {
        $this->_smtp_send('QUIT', false);
        $this->_socket->disconnect();
        $this->auth_type = null;
    }
    /**
     * Helper method to generate socket
     *
     * @throws \Cake\Network\Exception\SocketException
     */
    protected function _generate_socket(): void
    {
        $this->_socket = new Socket($this->_config);
    }
    /**
     * Protected method for sending data to SMTP connection
     *
     * @param string|null $data Data to be sent to SMTP server
     * @param string|false $checkCode Code to check for in server response, false to skip
     * @return string|null The matched code, or null if nothing matched
     * @throws \Cake\Network\Exception\SocketException
     */
    protected function _smtp_send(?string $data, string|false $check_code = '250'): ?string
    {
        $this->_last_response = [];
        if ($data !== null) {
            $this->_socket->write($data . "\r\n");
        }
        $timeout = $this->_config['timeout'];
        while ($check_code !== false) {
            $response = '';
            $start_time = time();
            while (!str_ends_with($response, "\r\n") && time() - $start_time < $timeout) {
                $bytes = $this->_socket->read();
                if ($bytes === null) {
                    break;
                }
                $response .= $bytes;
            }
            // Catch empty or malformed responses.
            if (!str_ends_with($response, "\r\n")) {
                // Use response message or assume operation timed out.
                throw new Socket_Exception($response ?: 'SMTP timeout.');
            }
            $response_lines = explode("\r\n", rtrim($response, "\r\n"));
            $response = end($response_lines);
            $this->_buffer_response_lines($response_lines);
            if (preg_match('/^(' . $check_code . ')(.)/', $response, $code)) {
                if ($code[2] === '-') {
                    continue;
                }
                return $code[1];
            }
            throw new Socket_Exception(sprintf('SMTP Error: %s', $response));
        }
        return null;
    }
}