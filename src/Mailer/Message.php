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
 * @since         4.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Mailer;

use Cake\Core\Configure;
use function Cake\Core\env;
use Cake\Http\Client\Form_Data_Part;
use Cake\Utility\Hash;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Closure;
use InvalidArgumentException;
use JsonSerializable;
use Psr\Http\Message\Uploaded_File_Interface;
use RuntimeException;
use Simple_Xml_Element;
/**
 * Email message class.
 *
 * This class is used for sending Internet Message Format based
 * on the standard outlined in https://www.rfc-editor.org/rfc/rfc2822.txt
 */
class Message implements JsonSerializable
{
    /**
     * Line length - should not exceed - RFC 2822 - 2.1.1
     *
     * @var int
     */
    public const LINE_LENGTH_SHOULD = 78;
    /**
     * Line length - must not exceed - RFC 2822 - 2.1.1
     *
     * @var int
     */
    public const LINE_LENGTH_MUST = 998;
    /**
     * Type of message - HTML
     *
     * @var string
     */
    public const MESSAGE_HTML = 'html';
    /**
     * Type of message - TEXT
     *
     * @var string
     */
    public const MESSAGE_TEXT = 'text';
    /**
     * Type of message - BOTH
     *
     * @var string
     */
    public const MESSAGE_BOTH = 'both';
    /**
     * Holds the regex pattern for email validation
     *
     * @var string
     */
    public const EMAIL_PATTERN = '/^((?:[\p{L}0-9.!#$%&\'*+\/=?^_`{|}~-]+)*@[\p{L}0-9-._]+)$/ui';
    /**
     * Recipient of the email
     */
    protected array $to = [];
    /**
     * The mail which the email is sent from
     */
    protected array $from = [];
    /**
     * The sender email
     */
    protected array $sender = [];
    /**
     * List of email(s) that the recipient will reply to
     */
    protected array $reply_to = [];
    /**
     * The read receipt email
     */
    protected array $read_receipt = [];
    /**
     * The mail that will be used in case of any errors like
     * - Remote mailserver down
     * - Remote user has exceeded his quota
     * - Unknown user
     */
    protected array $return_path = [];
    /**
     * Carbon Copy
     *
     * List of email's that should receive a copy of the email.
     * The Recipient WILL be able to see this list
     */
    protected array $cc = [];
    /**
     * Blind Carbon Copy
     *
     * List of email's that should receive a copy of the email.
     * The Recipient WILL NOT be able to see this list
     */
    protected array $bcc = [];
    /**
     * Message ID
     */
    protected string|bool $message_id = true;
    /**
     * Domain for messageId generation.
     * Needs to be manually set for CLI mailing as env('HTTP_HOST') is empty
     */
    protected string $domain = '';
    /**
     * The subject of the email
     */
    protected string $subject = '';
    /**
     * Associative array of a user defined headers
     * Keys will be prefixed 'X-' as per RFC2822 Section 4.7.5
     */
    protected array $headers = [];
    /**
     * Text message
     */
    protected string $text_message = '';
    /**
     * Html message
     */
    protected string $html_message = '';
    /**
     * Final message to send
     */
    protected array $message = [];
    /**
     * Available formats to be sent.
     *
     * @var array<string>
     */
    protected array $email_format_available = [self::MESSAGE_TEXT, self::MESSAGE_HTML, self::MESSAGE_BOTH];
    /**
     * What format should the email be sent in
     */
    protected string $email_format = self::MESSAGE_TEXT;
    /**
     * Charset the email body is sent in
     */
    protected string $charset = 'utf-8';
    /**
     * Charset the email header is sent in
     * If null, the $charset property will be used as default
     */
    protected ?string $header_charset = null;
    /**
     * The email transfer encoding used.
     * If null, the $charset property is used for determined the transfer encoding.
     */
    protected ?string $transfer_encoding = null;
    /**
     * Available encoding to be set for transfer.
     *
     * @var array<string>
     */
    protected array $transfer_encoding_available = ['7bit', '8bit', 'base64', 'binary', 'quoted-printable'];
    /**
     * The application wide charset, used to encode headers and body
     */
    protected ?string $app_charset = null;
    /**
     * List of files that should be attached to the email.
     *
     * Only absolute paths
     *
     * @var array<string, array>
     */
    protected array $attachments = [];
    /**
     * If set, boundary to use for multipart mime messages
     */
    protected ?string $boundary = null;
    /**
     * Contains the optional priority of the email.
     */
    protected ?int $priority = null;
    /**
     * 8Bit character sets
     *
     * @var array<string>
     */
    protected array $charset8bit = ['UTF-8', 'SHIFT_JIS'];
    /**
     * Define Content-Type charset name
     *
     * @var array<string, string>
     */
    protected array $content_type_charset = ['ISO-2022-JP-MS' => 'ISO-2022-JP'];
    /**
     * Regex for email validation
     *
     * If null, filter_var() will be used. Use the emailPattern() method
     * to set a custom pattern.
     */
    protected ?string $email_pattern = self::EMAIL_PATTERN;
    /**
     * Properties that could be serialized
     *
     * @var array<string>
     */
    protected array $serializable_properties = ['to', 'from', 'sender', 'replyTo', 'cc', 'bcc', 'subject', 'returnPath', 'readReceipt', 'emailFormat', 'emailPattern', 'domain', 'attachments', 'messageId', 'headers', 'appCharset', 'charset', 'headerCharset', 'textMessage', 'htmlMessage'];
    /**
     * Constructor
     *
     * @param array<string,mixed>|null $config Array of configs, or string to load configs from app.php
     */
    public function __construct(?array $config = null)
    {
        $this->app_charset = Configure::read('App.encoding');
        if ($this->app_charset !== null) {
            $this->charset = $this->app_charset;
        }
        $this->domain = (string) preg_replace('/\:\d+$/', '', (string) env('HTTP_HOST'));
        if (!$this->domain) {
            $this->domain = php_uname('n');
        }
        if ($config) {
            $this->set_config($config);
        }
    }
    /**
     * Sets "from" address.
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function set_from(array|string $email, ?string $name = null)
    {
        return $this->set_email_single('from', $email, $name, 'From requires only 1 email address.');
    }
    /**
     * Gets "from" address.
     */
    public function get_from(): array
    {
        return $this->from;
    }
    /**
     * Sets the "sender" address. See RFC link below for full explanation.
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     * @throws \InvalidArgumentException
     * @link https://tools.ietf.org/html/rfc2822.html#section-3.6.2
     */
    public function set_sender(array|string $email, ?string $name = null)
    {
        return $this->set_email_single('sender', $email, $name, 'Sender requires only 1 email address.');
    }
    /**
     * Gets the "sender" address. See RFC link below for full explanation.
     *
     * @link https://tools.ietf.org/html/rfc2822.html#section-3.6.2
     */
    public function get_sender(): array
    {
        return $this->sender;
    }
    /**
     * Sets "Reply-To" address.
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function set_reply_to(array|string $email, ?string $name = null)
    {
        return $this->set_email('replyTo', $email, $name);
    }
    /**
     * Gets "Reply-To" address.
     */
    public function get_reply_to(): array
    {
        return $this->reply_to;
    }
    /**
     * Add "Reply-To" address.
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     */
    public function add_reply_to(array|string $email, ?string $name = null)
    {
        return $this->add_email('replyTo', $email, $name);
    }
    /**
     * Sets Read Receipt (Disposition-Notification-To header).
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function set_read_receipt(array|string $email, ?string $name = null)
    {
        return $this->set_email_single('readReceipt', $email, $name, 'Disposition-Notification-To requires only 1 email address.');
    }
    /**
     * Gets Read Receipt (Disposition-Notification-To header).
     */
    public function get_read_receipt(): array
    {
        return $this->read_receipt;
    }
    /**
     * Sets return path.
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function set_return_path(array|string $email, ?string $name = null)
    {
        return $this->set_email_single('returnPath', $email, $name, 'Return-Path requires only 1 email address.');
    }
    /**
     * Gets return path.
     */
    public function get_return_path(): array
    {
        return $this->return_path;
    }
    /**
     * Sets "to" address.
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     */
    public function set_to(array|string $email, ?string $name = null)
    {
        return $this->set_email('to', $email, $name);
    }
    /**
     * Gets "to" address
     */
    public function get_to(): array
    {
        return $this->to;
    }
    /**
     * Add "To" address.
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     */
    public function add_to(array|string $email, ?string $name = null)
    {
        return $this->add_email('to', $email, $name);
    }
    /**
     * Sets "cc" address.
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     */
    public function set_cc(array|string $email, ?string $name = null)
    {
        return $this->set_email('cc', $email, $name);
    }
    /**
     * Gets "cc" address.
     */
    public function get_cc(): array
    {
        return $this->cc;
    }
    /**
     * Add "cc" address.
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     */
    public function add_cc(array|string $email, ?string $name = null)
    {
        return $this->add_email('cc', $email, $name);
    }
    /**
     * Sets "bcc" address.
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     */
    public function set_bcc(array|string $email, ?string $name = null)
    {
        return $this->set_email('bcc', $email, $name);
    }
    /**
     * Gets "bcc" address.
     */
    public function get_bcc(): array
    {
        return $this->bcc;
    }
    /**
     * Add "bcc" address.
     *
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     */
    public function add_bcc(array|string $email, ?string $name = null)
    {
        return $this->add_email('bcc', $email, $name);
    }
    /**
     * Charset setter.
     *
     * @param string $charset Character set.
     * @return $this
     */
    public function set_charset(string $charset): static
    {
        $this->charset = $charset;
        return $this;
    }
    /**
     * Charset getter.
     *
     * @return string Charset
     */
    public function get_charset(): string
    {
        return $this->charset;
    }
    /**
     * HeaderCharset setter.
     *
     * @param string|null $charset Character set.
     * @return $this
     */
    public function set_header_charset(?string $charset): static
    {
        $this->header_charset = $charset;
        return $this;
    }
    /**
     * HeaderCharset getter.
     *
     * @return string Charset
     */
    public function get_header_charset(): string
    {
        return $this->header_charset ?: $this->charset;
    }
    /**
     * TransferEncoding setter.
     *
     * @param string|null $encoding Encoding set.
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function set_transfer_encoding(?string $encoding): static
    {
        if ($encoding !== null) {
            $encoding = strtolower($encoding);
            if (!in_array($encoding, $this->transfer_encoding_available, true)) {
                throw new InvalidArgumentException(sprintf('Transfer encoding not available. Can be : %s.', implode(', ', $this->transfer_encoding_available)));
            }
        }
        $this->transfer_encoding = $encoding;
        return $this;
    }
    /**
     * TransferEncoding getter.
     *
     * @return string|null Encoding
     */
    public function get_transfer_encoding(): ?string
    {
        return $this->transfer_encoding;
    }
    /**
     * EmailPattern setter/getter
     *
     * @param string|null $regex The pattern to use for email address validation,
     *   null to unset the pattern and make use of filter_var() instead.
     * @return $this
     */
    public function set_email_pattern(?string $regex): static
    {
        $this->email_pattern = $regex;
        return $this;
    }
    /**
     * EmailPattern setter/getter
     */
    public function get_email_pattern(): ?string
    {
        return $this->email_pattern;
    }
    /**
     * Set email
     *
     * @param string $varName Property name
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     * @throws \InvalidArgumentException
     */
    protected function set_email(string $var_name, array|string $email, ?string $name): static
    {
        if (!is_array($email)) {
            $this->validate_email($email, $var_name);
            $this->{$var_name} = [$email => $name ?? $email];
            return $this;
        }
        $list = [];
        foreach ($email as $key => $value) {
            if (is_int($key)) {
                $key = $value;
            }
            $this->validate_email($key, $var_name);
            $list[$key] = $value ?? $key;
        }
        $this->{$var_name} = $list;
        return $this;
    }
    /**
     * Validate email address
     *
     * @param string $email Email address to validate
     * @param string $context Which property was set
     * @throws \InvalidArgumentException If email address does not validate
     */
    protected function validate_email(string $email, string $context): void
    {
        if ($this->email_pattern === null) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
        } elseif (preg_match($this->email_pattern, $email)) {
            return;
        }
        $context = ltrim($context, '_');
        if ($email === '') {
            throw new InvalidArgumentException(sprintf('The email set for `%s` is empty.', $context));
        }
        throw new InvalidArgumentException(sprintf('Invalid email set for `%s`. You passed `%s`.', $context, $email));
    }
    /**
     * Set only 1 email
     *
     * @param string $varName Property name
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @param string $throwMessage Exception message
     * @return $this
     * @throws \InvalidArgumentException
     */
    protected function set_email_single(string $var_name, array|string $email, ?string $name, string $throw_message): static
    {
        if ($email === []) {
            $this->{$var_name} = $email;
            return $this;
        }
        $current = $this->{$var_name};
        $this->set_email($var_name, $email, $name);
        if (count($this->{$var_name}) !== 1) {
            $this->{$var_name} = $current;
            throw new InvalidArgumentException($throw_message);
        }
        return $this;
    }
    /**
     * Add email
     *
     * @param string $varName Property name
     * @param array|string $email String with email,
     *   Array with email as key, name as value or email as value (without name)
     * @param string|null $name Name
     * @return $this
     * @throws \InvalidArgumentException
     */
    protected function add_email(string $var_name, array|string $email, ?string $name): static
    {
        if (!is_array($email)) {
            $this->validate_email($email, $var_name);
            $name ??= $email;
            $this->{$var_name}[$email] = $name;
            return $this;
        }
        $list = [];
        foreach ($email as $key => $value) {
            if (is_int($key)) {
                $key = $value;
            }
            $this->validate_email($key, $var_name);
            $list[$key] = $value;
        }
        $this->{$var_name} = array_merge($this->{$var_name}, $list);
        return $this;
    }
    /**
     * Sets subject.
     *
     * @param string $subject Subject string.
     * @return $this
     */
    public function set_subject(string $subject): static
    {
        $this->subject = $this->encode_for_header($subject);
        return $this;
    }
    /**
     * Gets subject.
     */
    public function get_subject(): string
    {
        return $this->subject;
    }
    /**
     * Get original subject without encoding
     *
     * @return string Original subject
     */
    public function get_original_subject(): string
    {
        return $this->decode_for_header($this->subject);
    }
    /**
     * Sets headers for the message
     *
     * @param array $headers Associative array containing headers to be set.
     * @return $this
     */
    public function set_headers(array $headers): static
    {
        $this->headers = $headers;
        return $this;
    }
    /**
     * Add header for the message
     *
     * @param array $headers Headers to set.
     * @return $this
     */
    public function add_headers(array $headers): static
    {
        $this->headers = Hash::merge($this->headers, $headers);
        return $this;
    }
    /**
     * Get list of headers
     *
     * ### Includes:
     *
     * - `from`
     * - `replyTo`
     * - `readReceipt`
     * - `returnPath`
     * - `to`
     * - `cc`
     * - `bcc`
     * - `subject`
     *
     * @param array<int|string, string> $include List of headers.
     * @return array<string, string>
     */
    public function get_headers(array $include = []): array
    {
        $this->create_boundary();
        if ($include === array_values($include)) {
            $include = array_fill_keys($include, true);
        }
        $defaults = array_fill_keys(['from', 'sender', 'replyTo', 'readReceipt', 'returnPath', 'to', 'cc', 'bcc', 'subject'], false);
        $include += $defaults;
        $headers = [];
        $relation = ['from' => 'From', 'replyTo' => 'Reply-To', 'readReceipt' => 'Disposition-Notification-To', 'returnPath' => 'Return-Path', 'to' => 'To', 'cc' => 'Cc', 'bcc' => 'Bcc'];
        $headers_multiple_emails = ['to', 'cc', 'bcc', 'replyTo'];
        foreach ($relation as $var => $header) {
            if ($include[$var]) {
                if (in_array($var, $headers_multiple_emails)) {
                    $headers[$header] = implode(', ', $this->format_address($this->{$var}));
                } else {
                    $headers[$header] = (string) current($this->format_address($this->{$var}));
                }
            }
        }
        if ($include['sender']) {
            if (key($this->sender) === key($this->from)) {
                $headers['Sender'] = '';
            } else {
                $headers['Sender'] = (string) current($this->format_address($this->sender));
            }
        }
        $headers += $this->headers;
        $headers['Date'] ??= date(DATE_RFC2822);
        if ($this->message_id !== false) {
            if ($this->message_id === true) {
                $this->message_id = '<' . str_replace('-', '', Text::uuid()) . '@' . $this->domain . '>';
            }
            $headers['Message-ID'] = $this->message_id;
        }
        if ($this->priority) {
            $headers['X-Priority'] = (string) $this->priority;
        }
        if ($include['subject']) {
            $headers['Subject'] = $this->subject;
        }
        $headers['MIME-Version'] = '1.0';
        if ($this->attachments) {
            $headers['Content-Type'] = 'multipart/mixed; boundary="' . $this->boundary . '"';
        } elseif ($this->email_format === static::MESSAGE_BOTH) {
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $this->boundary . '"';
        } elseif ($this->email_format === static::MESSAGE_TEXT) {
            $headers['Content-Type'] = 'text/plain; charset=' . $this->get_content_type_charset();
        } elseif ($this->email_format === static::MESSAGE_HTML) {
            $headers['Content-Type'] = 'text/html; charset=' . $this->get_content_type_charset();
        }
        $headers['Content-Transfer-Encoding'] = $this->get_content_transfer_encoding();
        return $headers;
    }
    /**
     * Get headers as string.
     *
     * @param array<string> $include List of headers.
     * @param string $eol End of line string for concatenating headers.
     * @param \Closure|null $callback Callback to run each header value through before stringifying.
     * @see Message::getHeaders()
     */
    public function get_headers_string(array $include = [], string $eol = "\r\n", ?Closure $callback = null): string
    {
        $lines = $this->get_headers($include);
        if ($callback) {
            $lines = array_map($callback, $lines);
        }
        $headers = [];
        foreach ($lines as $key => $value) {
            if ($value === '') {
                continue;
            }
            foreach ((array) $value as $val) {
                $headers[] = $key . ': ' . $val;
            }
        }
        return implode($eol, $headers);
    }
    /**
     * Format addresses
     *
     * If the address contains non alphanumeric/whitespace characters, it will
     * be quoted as characters like `:` and `,` are known to cause issues
     * in address header fields.
     *
     * @param array $address Addresses to format.
     * @return array<string>
     */
    public function format_address(array $address): array
    {
        $return = [];
        foreach ($address as $email => $alias) {
            if ($email === $alias) {
                $return[] = $email;
            } else {
                $encoded = $this->encode_for_header($alias);
                if (preg_match('/[^a-z0-9+\-\=? ]/i', $encoded)) {
                    $encoded = '"' . addcslashes($encoded, '"\\') . '"';
                }
                $return[] = sprintf('%s <%s>', $encoded, $email);
            }
        }
        return $return;
    }
    /**
     * Sets email format.
     *
     * @param string $format Formatting string.
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function set_email_format(string $format): static
    {
        if (!in_array($format, $this->email_format_available, true)) {
            throw new InvalidArgumentException('Format not available.');
        }
        $this->email_format = $format;
        return $this;
    }
    /**
     * Gets email format.
     */
    public function get_email_format(): string
    {
        return $this->email_format;
    }
    /**
     * Gets the body types that are in this email message
     *
     * @return array Array of types. Valid types are Email::MESSAGE_TEXT and Email::MESSAGE_HTML
     */
    public function get_body_types(): array
    {
        $format = $this->email_format;
        if ($format === static::MESSAGE_BOTH) {
            return [static::MESSAGE_HTML, static::MESSAGE_TEXT];
        }
        return [$format];
    }
    /**
     * Sets message ID.
     *
     * @param string|bool $message True to generate a new Message-ID, False to ignore (not send in email),
     *   String to set as Message-ID.
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function set_message_id(string|bool $message): static
    {
        if (is_bool($message)) {
            $this->message_id = $message;
        } else {
            if (!preg_match('/^\<.+@.+\>$/', $message)) {
                throw new InvalidArgumentException('Invalid format to Message-ID. The text should be something like "<uuid@server.com>"');
            }
            $this->message_id = $message;
        }
        return $this;
    }
    /**
     * Gets message ID.
     */
    public function get_message_id(): string|bool
    {
        return $this->message_id;
    }
    /**
     * Sets domain.
     *
     * Domain as top level (the part after @).
     *
     * @param string $domain Manually set the domain for CLI mailing.
     * @return $this
     */
    public function set_domain(string $domain): static
    {
        $this->domain = $domain;
        return $this;
    }
    /**
     * Gets domain.
     */
    public function get_domain(): string
    {
        return $this->domain;
    }
    /**
     * Add attachments to the email message
     *
     * Attachments can be defined in a few forms depending on how much control you need:
     *
     * Attach a file:
     *
     * ```
     * $this->setAttachments(['custom_name.txt' => 'path/to/file.txt']);
     * ```
     *
     * Attach a file and specify additional properties:
     *
     * ```
     * $this->setAttachments(['custom_name.png' => [
     *      'file' => 'path/to/file',
     *      'mimetype' => 'image/png',
     *      'contentId' => 'abc123',
     *      'contentDisposition' => false
     *    ]
     * ]);
     * ```
     *
     * Attach a file from string and specify additional properties:
     *
     * ```
     * $this->setAttachments(['custom_name.png' => [
     *      'data' => file_get_contents('path/to/file'),
     *      'mimetype' => 'image/png'
     *    ]
     * ]);
     * ```
     *
     * The `contentId` key allows you to specify an inline attachment. In your email text, you
     * can use `<img src="cid:abc123">` to display the image inline.
     *
     * The `contentDisposition` key allows you to disable the `Content-Disposition` header, this can improve
     * attachment compatibility with outlook email clients.
     *
     * @param array $attachments Array of filenames.
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function set_attachments(array $attachments): static
    {
        $attach = [];
        foreach ($attachments as $name => $file_info) {
            if (!is_array($file_info)) {
                $file_info = ['file' => $file_info];
            }
            if (!isset($file_info['file'])) {
                if (!isset($file_info['data'])) {
                    throw new InvalidArgumentException('No file or data specified.');
                }
                if (is_int($name)) {
                    throw new InvalidArgumentException('No filename specified.');
                }
                $file_info['data'] = chunk_split(base64_encode($file_info['data']), 76, "\r\n");
            } elseif ($file_info['file'] instanceof Uploaded_File_Interface) {
                $file_info['mimetype'] = $file_info['file']->get_client_media_type();
                if (is_int($name)) {
                    $name = $file_info['file']->get_client_filename();
                    assert(is_string($name));
                }
            } elseif (is_string($file_info['file'])) {
                $file_name = $file_info['file'];
                $file_info['file'] = realpath($file_info['file']);
                if ($file_info['file'] === false || !file_exists($file_info['file'])) {
                    throw new InvalidArgumentException(sprintf('File not found: `%s`', $file_name));
                }
                if (is_int($name)) {
                    $name = basename($file_info['file']);
                }
            } else {
                throw new InvalidArgumentException(sprintf('File must be a filepath or UploadedFileInterface instance. Found `%s` instead.', gettype($file_info['file'])));
            }
            if (!isset($file_info['mimetype']) && isset($file_info['file']) && is_string($file_info['file']) && function_exists('mime_content_type')) {
                $file_info['mimetype'] = mime_content_type($file_info['file']);
            }
            $file_info['mimetype'] ??= 'application/octet-stream';
            $attach[$name] = $file_info;
        }
        $this->attachments = $attach;
        return $this;
    }
    /**
     * Gets attachments to the email message.
     *
     * @return array<string, array> Array of attachments.
     */
    public function get_attachments(): array
    {
        return $this->attachments;
    }
    /**
     * Add attachment.
     *
     * @param \Psr\Http\Message\UploadedFileInterface|string $path Path to the file or UploadedFileInterface instance.
     * @param string|null $name Overrides the attachment name.
     * @param string|null $mimetype Mimetype of the file.
     * @param string|null $contentId Content ID for inline attachments.
     * @param bool|null $contentDisposition Allows you to disable the `Content-Disposition` header
     * @return $this
     */
    public function add_attachment(Uploaded_File_Interface|string $path, ?string $name = null, ?string $mimetype = null, ?string $content_id = null, ?bool $content_disposition = null): static
    {
        $name ??= 0;
        $this->add_attachments([$name => ['file' => $path, 'mimetype' => $mimetype, 'contentId' => $content_id, 'contentDisposition' => $content_disposition]]);
        return $this;
    }
    /**
     * Add attachments
     *
     * @param array $attachments Array of filenames.
     * @return $this
     * @throws \InvalidArgumentException
     * @see \Cake\Mailer\Email::setAttachments()
     */
    public function add_attachments(array $attachments): static
    {
        $current = $this->attachments;
        $this->set_attachments($attachments);
        $this->attachments = array_merge($current, $this->attachments);
        return $this;
    }
    /**
     * Get generated message body as array.
     */
    public function get_body(): array
    {
        if (!$this->message) {
            $this->message = $this->generate_message();
        }
        return $this->message;
    }
    /**
     * Get generated body as string.
     *
     * @param string $eol End of line string for imploding.
     * @see Message::getBody()
     */
    public function get_body_string(string $eol = "\r\n"): string
    {
        $lines = $this->get_body();
        return implode($eol, $lines);
    }
    /**
     * Create unique boundary identifier
     */
    protected function create_boundary(): void
    {
        if ($this->boundary === null && ($this->attachments || $this->email_format === static::MESSAGE_BOTH)) {
            $this->boundary = hash('xxh128', Security::random_bytes(16));
        }
    }
    /**
     * Generate full message.
     *
     * @return array<string>
     */
    protected function generate_message(): array
    {
        $this->create_boundary();
        $msg = [];
        $content_ids = array_filter((array) Hash::extract($this->attachments, '{s}.contentId'));
        $has_inline_attachments = $content_ids !== [];
        $has_attachments = $this->attachments !== [];
        $has_multiple_types = $this->email_format === static::MESSAGE_BOTH;
        $multi_part = $has_attachments || $has_multiple_types;
        $boundary = $this->boundary ?? '';
        $rel_boundary = $boundary;
        $text_boundary = $boundary;
        if ($has_inline_attachments) {
            $msg[] = '--' . $boundary;
            $msg[] = 'Content-Type: multipart/related; boundary="rel-' . $boundary . '"';
            $msg[] = '';
            $rel_boundary = 'rel-' . $boundary;
            $text_boundary = 'rel-' . $boundary;
        }
        if ($has_multiple_types && $has_attachments) {
            $msg[] = '--' . $rel_boundary;
            $msg[] = 'Content-Type: multipart/alternative; boundary="alt-' . $boundary . '"';
            $msg[] = '';
            $text_boundary = 'alt-' . $boundary;
        }
        if ($this->email_format === static::MESSAGE_TEXT || $this->email_format === static::MESSAGE_BOTH) {
            if ($multi_part) {
                $msg[] = '--' . $text_boundary;
                $msg[] = 'Content-Type: text/plain; charset=' . $this->get_content_type_charset();
                $msg[] = 'Content-Transfer-Encoding: ' . $this->get_content_transfer_encoding();
                $msg[] = '';
            }
            $content = explode("\n", $this->text_message);
            $msg = array_merge($msg, $content);
            $msg[] = '';
            $msg[] = '';
        }
        if ($this->email_format === static::MESSAGE_HTML || $this->email_format === static::MESSAGE_BOTH) {
            if ($multi_part) {
                $msg[] = '--' . $text_boundary;
                $msg[] = 'Content-Type: text/html; charset=' . $this->get_content_type_charset();
                $msg[] = 'Content-Transfer-Encoding: ' . $this->get_content_transfer_encoding();
                $msg[] = '';
            }
            $content = explode("\n", $this->html_message);
            $msg = array_merge($msg, $content);
            $msg[] = '';
            $msg[] = '';
        }
        if ($text_boundary !== $rel_boundary) {
            $msg[] = '--' . $text_boundary . '--';
            $msg[] = '';
        }
        if ($has_inline_attachments) {
            $attachments = $this->attach_inline_files($rel_boundary);
            $msg = array_merge($msg, $attachments);
            $msg[] = '';
            $msg[] = '--' . $rel_boundary . '--';
            $msg[] = '';
        }
        if ($has_attachments) {
            $attachments = $this->attach_files($boundary);
            $msg = array_merge($msg, $attachments);
        }
        if ($has_attachments || $has_multiple_types) {
            $msg[] = '';
            $msg[] = '--' . $boundary . '--';
            $msg[] = '';
        }
        return $msg;
    }
    /**
     * Attach non-embedded files by adding file contents inside boundaries.
     *
     * @param string|null $boundary Boundary to use. If null, will default to $this->boundary
     * @return array<string> An array of lines to add to the message
     */
    protected function attach_files(?string $boundary = null): array
    {
        $boundary ??= $this->boundary;
        $msg = [];
        foreach ($this->attachments as $filename => $file_info) {
            if (!empty($file_info['contentId'])) {
                continue;
            }
            $data = $file_info['data'] ?? $this->read_file($file_info['file']);
            $has_disposition = !isset($file_info['contentDisposition']) || $file_info['contentDisposition'];
            $part = new Form_Data_Part('', $data, '', $this->get_header_charset());
            if ($has_disposition) {
                $part->disposition('attachment');
                $part->filename($filename);
            }
            $part->transfer_encoding('base64');
            $part->type($file_info['mimetype']);
            $msg[] = '--' . $boundary;
            $msg[] = (string) $part;
            $msg[] = '';
        }
        return $msg;
    }
    /**
     * Attach inline/embedded files to the message.
     *
     * @param string|null $boundary Boundary to use. If null, will default to $this->boundary
     * @return array<string> An array of lines to add to the message
     */
    protected function attach_inline_files(?string $boundary = null): array
    {
        $boundary ??= $this->boundary;
        $msg = [];
        foreach ($this->get_attachments() as $filename => $file_info) {
            if (empty($file_info['contentId'])) {
                continue;
            }
            $data = $file_info['data'] ?? $this->read_file($file_info['file']);
            $msg[] = '--' . $boundary;
            $part = new Form_Data_Part('', $data, 'inline', $this->get_header_charset());
            $part->type($file_info['mimetype']);
            $part->transfer_encoding('base64');
            $part->content_id($file_info['contentId']);
            $part->filename($filename);
            $msg[] = (string) $part;
            $msg[] = '';
        }
        return $msg;
    }
    /**
     * Sets priority.
     *
     * @param int|null $priority 1 (highest) to 5 (lowest)
     * @return $this
     */
    public function set_priority(?int $priority): static
    {
        $this->priority = $priority;
        return $this;
    }
    /**
     * Gets priority.
     */
    public function get_priority(): ?int
    {
        return $this->priority;
    }
    /**
     * Sets the configuration for this instance.
     *
     * @param array<string, mixed> $config Config array.
     * @return $this
     */
    public function set_config(array $config): static
    {
        $simple_methods = ['from', 'sender', 'to', 'replyTo', 'readReceipt', 'returnPath', 'cc', 'bcc', 'messageId', 'domain', 'subject', 'attachments', 'emailFormat', 'emailPattern', 'charset', 'headerCharset'];
        foreach ($simple_methods as $method) {
            if (isset($config[$method])) {
                $this->{'set' . ucfirst($method)}($config[$method]);
            }
        }
        if (isset($config['headers'])) {
            $this->set_headers($config['headers']);
        }
        return $this;
    }
    /**
     * Set message body.
     *
     * @param array<string, string> $content Content array with keys "text" and/or "html" with
     *   content string of respective type.
     * @return $this
     */
    public function set_body(array $content): static
    {
        foreach ($content as $type => $text) {
            if (!in_array($type, $this->email_format_available, true)) {
                throw new InvalidArgumentException(sprintf('Invalid message type: `%s`. Valid types are: `text`, `html`.', $type));
            }
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            $text = $this->encode_string($text, $this->get_charset());
            $text = $this->wrap($text);
            $text = implode("\n", $text);
            $text = rtrim($text, "\n");
            $property = "{$type}Message";
            $this->{$property} = $text;
        }
        $this->boundary = null;
        $this->message = [];
        return $this;
    }
    /**
     * Set text body for message.
     *
     * @param string $content Content string
     * @return $this
     */
    public function set_body_text(string $content): static
    {
        $this->set_body([static::MESSAGE_TEXT => $content]);
        return $this;
    }
    /**
     * Set HTML body for message.
     *
     * @param string $content Content string
     * @return $this
     */
    public function set_body_html(string $content): static
    {
        $this->set_body([static::MESSAGE_HTML => $content]);
        return $this;
    }
    /**
     * Get text body of message.
     */
    public function get_body_text(): string
    {
        return $this->text_message;
    }
    /**
     * Get HTML body of message.
     */
    public function get_body_html(): string
    {
        return $this->html_message;
    }
    /**
     * Translates a string for one charset to another if the App.encoding value
     * differs and the mb_convert_encoding function exists
     *
     * @param string $text The text to be converted
     * @param string $charset the target encoding
     */
    protected function encode_string(string $text, string $charset): string
    {
        if ($this->app_charset === $charset) {
            return $text;
        }
        if ($this->app_charset === null) {
            $encoded = mb_convert_encoding($text, $charset);
            if ($encoded === false) {
                throw new RuntimeException('mb_convert_encoding failed.');
            }
            return $encoded;
        }
        $encoded = mb_convert_encoding($text, $charset, $this->app_charset);
        if ($encoded === false) {
            throw new RuntimeException('mb_convert_encoding failed.');
        }
        return $encoded;
    }
    /**
     * Wrap the message to follow the RFC 2822 - 2.1.1
     *
     * @param string|null $message Message to wrap
     * @param int $wrapLength The line length
     * @return array<string> Wrapped message
     */
    protected function wrap(?string $message = null, int $wrap_length = self::LINE_LENGTH_MUST): array
    {
        if ($message === null || $message === '') {
            return [''];
        }
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $lines = explode("\n", $message);
        $formatted = [];
        $cut = $wrap_length === static::LINE_LENGTH_MUST;
        foreach ($lines as $line) {
            if ($line === '') {
                $formatted[] = '';
                continue;
            }
            if (strlen($line) < $wrap_length) {
                $formatted[] = $line;
                continue;
            }
            if (!preg_match('/<[a-z]+.*>/i', $line)) {
                $formatted = array_merge($formatted, explode("\n", Text::word_wrap($line, $wrap_length, "\n", $cut)));
                continue;
            }
            $tag_open = false;
            $tmp_line = '';
            $tag = '';
            $tmp_line_length = 0;
            for ($i = 0, $count = strlen($line); $i < $count; $i++) {
                $char = $line[$i];
                if ($tag_open) {
                    $tag .= $char;
                    if ($char === '>') {
                        $tag_length = strlen($tag);
                        if ($tag_length + $tmp_line_length < $wrap_length) {
                            $tmp_line .= $tag;
                            $tmp_line_length += $tag_length;
                        } else {
                            if ($tmp_line_length > 0) {
                                $formatted = array_merge($formatted, explode("\n", Text::word_wrap(trim($tmp_line), $wrap_length, "\n", $cut)));
                                $tmp_line = '';
                                $tmp_line_length = 0;
                            }
                            if ($tag_length > $wrap_length) {
                                $formatted[] = $tag;
                            } else {
                                $tmp_line = $tag;
                                $tmp_line_length = $tag_length;
                            }
                        }
                        $tag = '';
                        $tag_open = false;
                    }
                    continue;
                }
                if ($char === '<') {
                    $tag_open = true;
                    $tag = '<';
                    continue;
                }
                if ($char === ' ' && $tmp_line_length >= $wrap_length) {
                    $formatted[] = $tmp_line;
                    $tmp_line_length = 0;
                    continue;
                }
                $tmp_line .= $char;
                $tmp_line_length++;
                if ($tmp_line_length === $wrap_length) {
                    $next_char = $line[$i + 1] ?? '';
                    if ($next_char === ' ' || $next_char === '<') {
                        $formatted[] = trim($tmp_line);
                        $tmp_line = '';
                        $tmp_line_length = 0;
                        if ($next_char === ' ') {
                            $i++;
                        }
                    } else {
                        $last_space = strrpos($tmp_line, ' ');
                        if ($last_space === false) {
                            continue;
                        }
                        $formatted[] = trim(substr($tmp_line, 0, $last_space));
                        $tmp_line = substr($tmp_line, $last_space + 1);
                        $tmp_line_length = strlen($tmp_line);
                    }
                }
            }
            if ($tmp_line) {
                $formatted[] = $tmp_line;
            }
        }
        $formatted[] = '';
        return $formatted;
    }
    /**
     * Reset all the internal variables to be able to send out a new email.
     *
     * @return $this
     */
    public function reset(): static
    {
        $this->to = [];
        $this->from = [];
        $this->sender = [];
        $this->reply_to = [];
        $this->read_receipt = [];
        $this->return_path = [];
        $this->cc = [];
        $this->bcc = [];
        $this->message_id = true;
        $this->subject = '';
        $this->headers = [];
        $this->text_message = '';
        $this->html_message = '';
        $this->message = [];
        $this->email_format = static::MESSAGE_TEXT;
        $this->priority = null;
        $this->charset = 'utf-8';
        $this->header_charset = null;
        $this->transfer_encoding = null;
        $this->attachments = [];
        $this->email_pattern = static::EMAIL_PATTERN;
        return $this;
    }
    /**
     * Encode the specified string using the current charset
     *
     * @param string $text String to encode
     * @return string Encoded string
     */
    protected function encode_for_header(string $text): string
    {
        if ($this->app_charset === null) {
            return $text;
        }
        $restore = mb_internal_encoding();
        mb_internal_encoding($this->app_charset);
        $return = mb_encode_mimeheader($text, $this->get_header_charset(), 'B');
        mb_internal_encoding($restore);
        return $return;
    }
    /**
     * Decode the specified string
     *
     * @param string $text String to decode
     * @return string Decoded string
     */
    protected function decode_for_header(string $text): string
    {
        if ($this->app_charset === null) {
            return $text;
        }
        $restore = mb_internal_encoding();
        mb_internal_encoding($this->app_charset);
        $return = mb_decode_mimeheader($text);
        mb_internal_encoding($restore);
        return $return;
    }
    /**
     * Read the file contents and return a base64 version of the file contents.
     *
     * @param \Psr\Http\Message\UploadedFileInterface|string $file The absolute path to the file to read
     *   or UploadedFileInterface instance.
     * @return string File contents in base64 encoding
     */
    protected function read_file(Uploaded_File_Interface|string $file): string
    {
        if (is_string($file)) {
            $content = (string) file_get_contents($file);
        } else {
            $content = (string) $file->get_stream();
        }
        return chunk_split(base64_encode($content));
    }
    /**
     * Return the Content-Transfer Encoding value based
     * on the set transferEncoding or set charset.
     */
    public function get_content_transfer_encoding(): string
    {
        if ($this->transfer_encoding) {
            return $this->transfer_encoding;
        }
        $charset = strtoupper($this->charset);
        if (in_array($charset, $this->charset8bit, true)) {
            return '8bit';
        }
        return '7bit';
    }
    /**
     * Return charset value for Content-Type.
     *
     * Checks fallback/compatibility types which include workarounds
     * for legacy japanese character sets.
     */
    public function get_content_type_charset(): string
    {
        $charset = strtoupper($this->charset);
        if (array_key_exists($charset, $this->content_type_charset)) {
            return strtoupper($this->content_type_charset[$charset]);
        }
        return strtoupper($this->charset);
    }
    /**
     * Serializes the email object to a value that can be natively serialized and re-used
     * to clone this email instance.
     *
     * @return array Serializable array of configuration properties.
     * @throws \Exception When a view var object can not be properly serialized.
     */
    public function jsonSerialize(): array
    {
        $array = [];
        foreach ($this->serializable_properties as $property) {
            $array[$property] = $this->{$property};
        }
        array_walk($array['attachments'], function (array &$item): void {
            if (!empty($item['file'])) {
                $item['data'] = $this->read_file($item['file']);
                unset($item['file']);
            }
        });
        return array_filter($array, fn($i) => $i !== null && !is_array($i) && !is_bool($i) && strlen((string) $i) || !empty($i));
    }
    /**
     * Configures an email instance object from serialized config.
     *
     * @param array<string, mixed> $config Email configuration array.
     * @return $this
     */
    public function create_from_array(array $config): static
    {
        foreach ($config as $property => $value) {
            $this->{$property} = $value;
        }
        return $this;
    }
    /**
     * Magic method used for serializing the Message object.
     */
    public function __serialize(): array
    {
        $array = $this->jsonSerialize();
        array_walk_recursive($array, function (&$item): void {
            if ($item instanceof Simple_Xml_Element) {
                $item = json_decode((string) json_encode((array) $item), true);
            }
        });
        return $array;
    }
    /**
     * Magic method used to rebuild the Message object.
     *
     * @param array $data Data array.
     */
    public function __unserialize(array $data): void
    {
        $this->create_from_array($data);
    }
}