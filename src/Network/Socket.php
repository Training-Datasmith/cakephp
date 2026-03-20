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
 * @since         1.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Network;

use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Instance_Config_Trait;
use Cake\Network\Exception\Socket_Exception;
use Cake\Validation\Validation;
use Composer\Ca_Bundle\Ca_Bundle;
use Exception;
use InvalidArgumentException;
/**
 * CakePHP network socket connection class.
 *
 * Core base class for network communication.
 */
class Socket
{
    use Instance_Config_Trait;
    /**
     * Default configuration settings for the socket connection
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['persistent' => false, 'host' => 'localhost', 'protocol' => 'tcp', 'port' => 80, 'timeout' => 30];
    /**
     * Reference to socket connection resource
     *
     * @var resource|null
     */
    protected $connection;
    /**
     * This boolean contains the current state of the Socket class
     *
     * @deprecated 5.2.9 Use isConnected() instead.
     */
    protected bool $connected = false;
    /**
     * This variable contains an array with the last error number (num) and string (str)
     *
     * @var array<string, mixed>
     */
    protected array $last_error = [];
    /**
     * True if the socket stream is encrypted after a {@link \Cake\Network\Socket::enableCrypto()} call
     */
    protected bool $encrypted = false;
    /**
     * Contains all the encryption methods available
     *
     * @var array<string, int>
     */
    protected array $_encrypt_methods = ['sslv23_client' => Stream_crypto_method_ss_Lv23_client, 'tls_client' => STREAM_CRYPTO_METHOD_TLS_CLIENT, 'tlsv10_client' => Stream_crypto_method_tl_Sv1_0_client, 'tlsv11_client' => Stream_crypto_method_tl_Sv1_1_client, 'tlsv12_client' => Stream_crypto_method_tl_Sv1_2_client, 'sslv23_server' => Stream_crypto_method_ss_Lv23_server, 'tls_server' => STREAM_CRYPTO_METHOD_TLS_SERVER, 'tlsv10_server' => Stream_crypto_method_tl_Sv1_0_server, 'tlsv11_server' => Stream_crypto_method_tl_Sv1_1_server, 'tlsv12_server' => Stream_crypto_method_tl_Sv1_2_server];
    /**
     * Used to capture connection warnings which can happen when there are
     * SSL errors for example.
     *
     * @var array<string>
     */
    protected array $_connection_errors = [];
    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Socket configuration, which will be merged with the base configuration
     * @see \Cake\Network\Socket::$_defaultConfig
     */
    public function __construct(array $config = [])
    {
        $this->set_config($config);
    }
    /**
     * Connect the socket to the given host and port.
     *
     * @return bool Success
     * @throws \Cake\Network\Exception\SocketException
     */
    public function connect(): bool
    {
        if ($this->connection) {
            $this->disconnect();
        }
        if (str_contains((string) $this->_config['host'], '://')) {
            [$this->_config['protocol'], $this->_config['host']] = explode('://', (string) $this->_config['host']);
        }
        $scheme = null;
        if (!empty($this->_config['protocol'])) {
            $scheme = $this->_config['protocol'] . '://';
        }
        $this->_set_ssl_context($this->_config['host']);
        if (!empty($this->_config['context'])) {
            $context = stream_context_create($this->_config['context']);
        } else {
            $context = stream_context_create();
        }
        $connect_as = STREAM_CLIENT_CONNECT;
        if ($this->_config['persistent']) {
            $connect_as |= STREAM_CLIENT_PERSISTENT;
        }
        /**
         * @phpstan-ignore-next-line
         */
        set_error_handler($this->_connection_error_handler(...));
        $remote_socket_target = $scheme . $this->_config['host'];
        $port = (int) $this->_config['port'];
        if ($port > 0) {
            $remote_socket_target .= ':' . $port;
        }
        $err_num = 0;
        $err_str = '';
        $this->connection = $this->_get_stream_socket_client($remote_socket_target, $err_num, $err_str, (int) $this->_config['timeout'], $connect_as, $context);
        restore_error_handler();
        if ($this->connection === null && (!$err_num || !$err_str)) {
            $this->set_last_error($err_num ?? 0, $err_str ?? '');
            throw new Socket_Exception($err_str ?? '', $err_num ?? 0);
        }
        if ($this->connection === null && $this->_connection_errors) {
            $message = implode("\n", $this->_connection_errors);
            throw new Socket_Exception($message, E_WARNING);
        }
        $connected = is_resource($this->connection);
        $this->connected = $connected;
        if ($connected) {
            assert($this->connection !== null);
            stream_set_timeout($this->connection, (int) $this->_config['timeout']);
        }
        return $connected;
    }
    /**
     * Check the connection status after calling `connect()`.
     */
    public function is_connected(): bool
    {
        return is_resource($this->connection);
    }
    /**
     * Create a stream socket client. Mock utility.
     *
     * @param string $remoteSocketTarget remote socket
     * @param int|null $errNum error number
     * @param string|null $errStr error string
     * @param int $timeout timeout
     * @param int<0, 7> $connectAs flags
     * @param resource $context context
     * @return resource|null
     */
    protected function _get_stream_socket_client(string $remote_socket_target, ?int &$err_num, ?string &$err_str, int $timeout, int $connect_as, $context)
    {
        $resource = stream_socket_client($remote_socket_target, $err_num, $err_str, $timeout, $connect_as, $context);
        if (!$resource) {
            return null;
        }
        return $resource;
    }
    /**
     * Configure the SSL context options.
     *
     * @param string $host The host name being connected to.
     */
    protected function _set_ssl_context(string $host): void
    {
        foreach ($this->_config as $key => $value) {
            if (!str_starts_with($key, 'ssl_')) {
                continue;
            }
            $context_key = substr($key, 4);
            if (empty($this->_config['context']['ssl'][$context_key])) {
                $this->_config['context']['ssl'][$context_key] = $value;
            }
            unset($this->_config[$key]);
        }
        $this->_config['context']['ssl']['SNI_enabled'] ??= true;
        if (empty($this->_config['context']['ssl']['peer_name'])) {
            $this->_config['context']['ssl']['peer_name'] = $host;
        }
        if (empty($this->_config['context']['ssl']['cafile'])) {
            $this->_config['context']['ssl']['cafile'] = Ca_Bundle::get_bundled_ca_bundle_path();
        }
        if (!empty($this->_config['context']['ssl']['verify_host'])) {
            $this->_config['context']['ssl']['CN_match'] = $host;
        }
        unset($this->_config['context']['ssl']['verify_host']);
    }
    /**
     * stream_socket_client() does not populate errNum, or $errStr when there are
     * connection errors, as in the case of SSL verification failure.
     *
     * Instead, we need to handle those errors manually.
     *
     * @param int $code Code number.
     * @param string $message Message.
     */
    protected function _connection_error_handler(int $code, string $message): void
    {
        $this->_connection_errors[] = $message;
    }
    /**
     * Get the connection context.
     *
     * @return array<string, mixed>|null Null when there is no connection, an array when there is.
     */
    public function context(): ?array
    {
        if (!$this->connection) {
            return null;
        }
        return stream_context_get_options($this->connection);
    }
    /**
     * Get the host name of the current connection.
     *
     * @return string Host name
     */
    public function host(): string
    {
        if (Validation::ip($this->_config['host'])) {
            return (string) gethostbyaddr($this->_config['host']);
        }
        return (string) gethostbyaddr($this->address());
    }
    /**
     * Get the IP address of the current connection.
     *
     * @return string IP address
     */
    public function address(): string
    {
        if (Validation::ip($this->_config['host'])) {
            return $this->_config['host'];
        }
        return gethostbyname($this->_config['host']);
    }
    /**
     * Get all IP addresses associated with the current connection.
     *
     * @return array<string> IP addresses
     */
    public function addresses(): array
    {
        if (Validation::ip($this->_config['host'])) {
            return [$this->_config['host']];
        }
        return gethostbynamel($this->_config['host']) ?: [];
    }
    /**
     * Get the last error as a string.
     *
     * @return string|null Last error
     */
    public function last_error(): ?string
    {
        if (!$this->last_error) {
            return null;
        }
        return $this->last_error['num'] . ': ' . $this->last_error['str'];
    }
    /**
     * Set the last error.
     *
     * @param int|null $errNum Error code
     * @param string $errStr Error string
     */
    public function set_last_error(?int $err_num, string $err_str): void
    {
        $this->last_error = ['num' => $err_num, 'str' => $err_str];
    }
    /**
     * Write data to the socket.
     *
     * @param string $data The data to write to the socket.
     * @return int Bytes written.
     */
    public function write(string $data): int
    {
        if (!$this->is_connected() && !$this->connect()) {
            return 0;
        }
        $total_bytes = strlen($data);
        $written = 0;
        while ($written < $total_bytes) {
            assert($this->connection !== null);
            $rv = fwrite($this->connection, substr($data, $written));
            if ($rv === false || $rv === 0) {
                return $written;
            }
            $written += $rv;
        }
        return $written;
    }
    /**
     * Read data from the socket. Returns null if no data is available or no connection could be
     * established.
     *
     * @param int $length Optional buffer length to read; defaults to 1024
     * @return string|null Socket data
     */
    public function read(int $length = 1024): ?string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Length must be greater than `0`');
        }
        if (!$this->is_connected() && !$this->connect()) {
            return null;
        }
        assert($this->connection !== null);
        if (feof($this->connection)) {
            return null;
        }
        $buffer = fread($this->connection, $length);
        $info = stream_get_meta_data($this->connection);
        if ($info['timed_out']) {
            $this->set_last_error(E_WARNING, 'Connection timed out');
            return null;
        }
        return $buffer === false ? null : $buffer;
    }
    /**
     * Disconnect the socket from the current connection.
     *
     * @return bool Success
     */
    public function disconnect(): bool
    {
        if (!is_resource($this->connection)) {
            $this->connected = false;
            return true;
        }
        $this->connected = !fclose($this->connection);
        if (!$this->connected) {
            $this->connection = null;
        }
        return !$this->connected;
    }
    /**
     * Destructor, used to disconnect from current connection.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
    /**
     * Resets the state of this Socket instance to its initial state (before __construct() got executed)
     *
     * @param array|null $state Array with key and values to reset
     */
    public function reset(?array $state = null): void
    {
        if (!$state) {
            static $initial_state = [];
            if (!$initial_state) {
                $initial_state = get_class_vars(self::class);
            }
            $state = $initial_state;
        }
        foreach ($state as $property => $value) {
            $this->{$property} = $value;
        }
    }
    /**
     * Encrypts current stream socket, using one of the defined encryption methods
     *
     * @param string $type can be one of 'ssl2', 'ssl3', 'ssl23' or 'tls'
     * @param string $clientOrServer can be one of 'client', 'server'. Default is 'client'
     * @param bool $enable enable or disable encryption. Default is true (enable)
     * @throws \InvalidArgumentException When an invalid encryption scheme is chosen.
     * @throws \Cake\Network\Exception\SocketException When attempting to enable SSL/TLS fails
     * @see stream_socket_enable_crypto
     */
    public function enable_crypto(string $type, string $client_or_server = 'client', bool $enable = true): void
    {
        if (!array_key_exists($type . '_' . $client_or_server, $this->_encrypt_methods)) {
            throw new InvalidArgumentException('Invalid encryption scheme chosen');
        }
        $method = $this->_encrypt_methods[$type . '_' . $client_or_server];
        if ($method === STREAM_CRYPTO_METHOD_TLS_CLIENT) {
            $method |= Stream_crypto_method_tl_Sv1_1_client | Stream_crypto_method_tl_Sv1_2_client;
        }
        if ($method === STREAM_CRYPTO_METHOD_TLS_SERVER) {
            $method |= Stream_crypto_method_tl_Sv1_1_server | Stream_crypto_method_tl_Sv1_2_server;
        }
        try {
            if ($this->connection === null) {
                throw new Cake_Exception('You must call connect() first.');
            }
            $enable_crypto_result = stream_socket_enable_crypto($this->connection, $enable, $method);
        } catch (Exception $e) {
            $this->set_last_error(null, $e->get_message());
            throw new Socket_Exception($e->get_message(), null, $e);
        }
        if ($enable_crypto_result === true) {
            $this->encrypted = $enable;
            return;
        }
        $error_message = 'Unable to perform enableCrypto operation on the current socket';
        $this->set_last_error(null, $error_message);
        throw new Socket_Exception($error_message);
    }
    /**
     * Check the encryption status after calling `enableCrypto()`.
     */
    public function is_encrypted(): bool
    {
        return $this->encrypted;
    }
}