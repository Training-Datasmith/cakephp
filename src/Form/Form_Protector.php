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
namespace Cake\Form;

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Cake\Utility\Security;
/**
 * Protects against form tampering. It ensures that:
 *
 * - Form's action (URL) is not modified.
 * - Unknown / extra fields are not added to the form.
 * - Existing fields have not been removed from the form.
 * - Values of hidden inputs have not been changed.
 *
 * @internal
 */
class Form_Protector
{
    /**
     * Fields list.
     */
    protected array $fields = [];
    /**
     * Unlocked fields.
     *
     * @var array<string>
     */
    protected array $unlocked_fields = [];
    /**
     * Error message providing detail for failed validation.
     */
    protected ?string $debug_message = null;
    /**
     * Validate submitted form data.
     *
     * @param mixed $formData Form data.
     * @param string $url URL form was POSTed to.
     * @param string $sessionId Session id for hash generation.
     */
    public function validate(mixed $form_data, string $url, string $session_id): bool
    {
        $this->debug_message = null;
        $extracted_token = $this->extract_token($form_data);
        if (!$extracted_token) {
            return false;
        }
        $hash_parts = $this->extract_hash_parts($form_data);
        $generated_token = $this->generate_hash($hash_parts['fields'], $hash_parts['unlockedFields'], $url, $session_id);
        if (hash_equals($generated_token, $extracted_token)) {
            return true;
        }
        if (Configure::read('debug')) {
            $debug_message = $this->debug_token_not_matching($form_data, $hash_parts + compact('url', 'sessionId'));
            if ($debug_message) {
                $this->debug_message = $debug_message;
            }
        }
        return false;
    }
    /**
     * Construct.
     *
     * @param array<string, mixed> $data Data array, can contain key `unlockedFields` with list of unlocked fields.
     */
    public function __construct(array $data = [])
    {
        if (!empty($data['unlockedFields'])) {
            $this->unlocked_fields = $data['unlockedFields'];
        }
    }
    /**
     * Determine which fields of a form should be used for hash.
     *
     * @param array<string>|string $field Reference to field to be secured. Can be dot
     *   separated string to indicate nesting or array of fieldname parts.
     * @param bool $lock Whether this field should be part of the validation
     *   or excluded as part of the unlockedFields. Default `true`.
     * @param mixed $value Field value, if value should not be tampered with.
     * @return $this
     */
    public function add_field(array|string $field, bool $lock = true, mixed $value = null): static
    {
        if (is_string($field)) {
            $field = $this->get_field_name_array($field);
        }
        if (!$field) {
            return $this;
        }
        foreach ($this->unlocked_fields as $unlock_field) {
            $unlock_parts = explode('.', $unlock_field);
            if (array_values(array_intersect($field, $unlock_parts)) === $unlock_parts) {
                return $this;
            }
        }
        $field = implode('.', $field);
        $field = (string) preg_replace('/(\.\d+)+$/', '', $field);
        if ($lock) {
            if (!in_array($field, $this->fields, true)) {
                if ($value !== null) {
                    $this->fields[$field] = $value;
                    return $this;
                }
                if (isset($this->fields[$field])) {
                    unset($this->fields[$field]);
                }
                $this->fields[] = $field;
            }
        } else {
            $this->unlock_field($field);
        }
        return $this;
    }
    /**
     * Parses the field name to create a dot separated name value for use in
     * field hash. If fieldname is of form Model[field] or Model.field an array of
     * fieldname parts like ['Model', 'field'] is returned.
     *
     * @param string $name The form inputs name attribute.
     * @return array<string> Array of field name params like ['Model.field'] or
     *   ['Model', 'field'] for array fields or empty array if $name is empty.
     */
    protected function get_field_name_array(string $name): array
    {
        if ($name === '') {
            return [];
        }
        if (!str_contains($name, '[')) {
            return Hash::filter(explode('.', $name));
        }
        $parts = explode('[', $name);
        $parts = array_map(fn(string $el) => trim($el, ']'), $parts);
        return Hash::filter($parts, 'strlen');
    }
    /**
     * Add to the list of fields that are currently unlocked.
     *
     * Unlocked fields are not included in the field hash.
     *
     * @param string $name The dot separated name for the field.
     * @return $this
     */
    public function unlock_field(string $name): static
    {
        if (!in_array($name, $this->unlocked_fields, true)) {
            $this->unlocked_fields[] = $name;
        }
        $index = array_search($name, $this->fields, true);
        if ($index !== false) {
            unset($this->fields[$index]);
        }
        unset($this->fields[$name]);
        return $this;
    }
    /**
     * Get validation error message.
     */
    public function get_error(): ?string
    {
        return $this->debug_message;
    }
    /**
     * Extract token from data.
     *
     * @param mixed $formData Data to validate.
     * @return string|null Fields token on success, null on failure.
     */
    protected function extract_token(mixed $form_data): ?string
    {
        if (!is_array($form_data)) {
            $this->debug_message = 'Request data is not an array.';
            return null;
        }
        $message = '`%s` was not found in request data.';
        if (!isset($form_data['_Token'])) {
            $this->debug_message = sprintf($message, '_Token');
            return null;
        }
        if (!isset($form_data['_Token']['fields'])) {
            $this->debug_message = sprintf($message, '_Token.fields');
            return null;
        }
        if (!is_string($form_data['_Token']['fields'])) {
            $this->debug_message = '`_Token.fields` is invalid.';
            return null;
        }
        if (!isset($form_data['_Token']['unlocked'])) {
            $this->debug_message = sprintf($message, '_Token.unlocked');
            return null;
        }
        if (Configure::read('debug') && !isset($form_data['_Token']['debug'])) {
            $this->debug_message = sprintf($message, '_Token.debug');
            return null;
        }
        if (!Configure::read('debug') && isset($form_data['_Token']['debug'])) {
            $this->debug_message = 'Unexpected `_Token.debug` found in request data';
            return null;
        }
        $token = urldecode($form_data['_Token']['fields']);
        if (str_contains($token, ':')) {
            [$token] = explode(':', $token, 2);
        }
        return $token;
    }
    /**
     * Return hash parts for the token generation
     *
     * @param array<string, array> $formData Form data.
     * @return array<string, array> Contains 'fields' and 'unlockedFields' keys. Additional keys allowed.
     * @phpstan-return array{fields: array, unlockedFields: array<string>, ...}
     */
    protected function extract_hash_parts(array $form_data): array
    {
        $fields = $this->extract_fields($form_data);
        $unlocked_fields = $this->sorted_unlocked_fields($form_data);
        return ['fields' => $fields, 'unlockedFields' => $unlocked_fields];
    }
    /**
     * Return the fields list for the hash calculation
     *
     * @param array $formData Data array
     */
    protected function extract_fields(array $form_data): array
    {
        $locked = '';
        $token = urldecode((string) $form_data['_Token']['fields']);
        $unlocked = urldecode((string) $form_data['_Token']['unlocked']);
        if (str_contains($token, ':')) {
            [, $locked] = explode(':', $token, 2);
        }
        unset($form_data['_Token']);
        $locked = $locked ? explode('|', $locked) : [];
        $unlocked = $unlocked ? explode('|', $unlocked) : [];
        $fields = Hash::flatten($form_data);
        $field_list = array_keys($fields);
        $multi = [];
        $locked_fields = [];
        $is_unlocked = false;
        foreach ($field_list as $i => $key) {
            if (is_string($key) && preg_match('/(\.\d+){1,10}$/', $key)) {
                $multi[$i] = preg_replace('/(\.\d+){1,10}$/', '', $key);
                unset($field_list[$i]);
            } else {
                $field_list[$i] = (string) $key;
            }
        }
        if ($multi) {
            $field_list += array_unique($multi);
        }
        $unlocked_fields = array_unique(array_merge($this->unlocked_fields, $unlocked));
        /** @var string $key */
        foreach ($field_list as $i => $key) {
            $is_locked = in_array($key, $locked, true);
            foreach ($unlocked_fields as $off) {
                $off = explode('.', $off);
                $field = array_values(array_intersect(explode('.', $key), $off));
                $is_unlocked = $field === $off;
                if ($is_unlocked) {
                    break;
                }
            }
            if ($is_unlocked || $is_locked) {
                unset($field_list[$i]);
                if ($is_locked) {
                    $locked_fields[$key] = $fields[$key];
                }
            }
        }
        sort($field_list, SORT_STRING);
        ksort($locked_fields, SORT_STRING);
        return $field_list + $locked_fields;
    }
    /**
     * Get the sorted unlocked string
     *
     * @param array $formData Data array
     * @return array<string>
     */
    protected function sorted_unlocked_fields(array $form_data): array
    {
        $unlocked = urldecode((string) $form_data['_Token']['unlocked']);
        if (!$unlocked) {
            return [];
        }
        $unlocked = explode('|', $unlocked);
        sort($unlocked, SORT_STRING);
        return $unlocked;
    }
    /**
     * Generate the token data.
     *
     * @param string $url Form URL.
     * @param string $sessionId Session ID.
     * @return array<string, string> The token data. Contains 'fields', 'unlocked', and 'debug' keys. Additional keys allowed.
     * @phpstan-return array{fields: string, unlocked: string, debug: string, ...}
     */
    public function build_token_data(string $url = '', string $session_id = ''): array
    {
        $fields = $this->fields;
        $unlocked_fields = $this->unlocked_fields;
        $locked = [];
        foreach ($fields as $key => $value) {
            if ($value === true) {
                $value = '1';
            } elseif ($value === false) {
                $value = '0';
            } elseif (is_numeric($value)) {
                $value = (string) $value;
            }
            if (!is_int($key)) {
                $locked[$key] = $value;
                unset($fields[$key]);
            }
        }
        sort($unlocked_fields, SORT_STRING);
        sort($fields, SORT_STRING);
        ksort($locked, SORT_STRING);
        $fields += $locked;
        $fields = $this->generate_hash($fields, $unlocked_fields, $url, $session_id);
        $locked = implode('|', array_keys($locked));
        return ['fields' => urlencode($fields . ':' . $locked), 'unlocked' => urlencode(implode('|', $unlocked_fields)), 'debug' => urlencode((string) json_encode([$url, $this->fields, $this->unlocked_fields]))];
    }
    /**
     * Generate validation hash.
     *
     * @param array $fields Fields list.
     * @param array<string> $unlockedFields Unlocked fields.
     * @param string $url Form URL.
     * @param string $sessionId Session Id.
     */
    protected function generate_hash(array $fields, array $unlocked_fields, string $url, string $session_id): string
    {
        $hash_parts = [$url, serialize($fields), implode('|', $unlocked_fields), $session_id];
        return hash_hmac('sha1', implode('', $hash_parts), Security::get_salt());
    }
    /**
     * Create a message for humans to understand why Security token is not matching
     *
     * @param array $formData Data.
     * @param array $hashParts Elements used to generate the Token hash
     * @return string Message explaining why the tokens are not matching
     */
    protected function debug_token_not_matching(array $form_data, array $hash_parts): string
    {
        $messages = [];
        if (!isset($form_data['_Token']['debug'])) {
            return 'Form protection debug token not found.';
        }
        $expected_parts = json_decode(urldecode($form_data['_Token']['debug']), true);
        if (!is_array($expected_parts) || count($expected_parts) !== 3) {
            return 'Invalid form protection debug token.';
        }
        $expected_url = Hash::get($expected_parts, 0);
        $url = Hash::get($hash_parts, 'url');
        if ($expected_url !== $url) {
            $messages[] = sprintf('URL mismatch in POST data (expected `%s` but found `%s`)', $expected_url, $url);
        }
        $expected_fields = Hash::get($expected_parts, 1);
        $data_fields = Hash::get($hash_parts, 'fields') ?: [];
        $fields_messages = $this->debug_check_fields((array) $data_fields, $expected_fields, 'Unexpected field `%s` in POST data', 'Tampered field `%s` in POST data (expected value `%s` but found `%s`)', 'Missing field `%s` in POST data');
        $expected_unlocked_fields = Hash::get($expected_parts, 2);
        $data_unlocked_fields = Hash::get($hash_parts, 'unlockedFields') ?: [];
        $unlock_fields_messages = $this->debug_check_fields((array) $data_unlocked_fields, $expected_unlocked_fields, 'Unexpected unlocked field `%s` in POST data', '', 'Missing unlocked field: `%s`');
        $messages = array_merge($messages, $fields_messages, $unlock_fields_messages);
        return implode(', ', $messages);
    }
    /**
     * Iterates data array to check against expected
     *
     * @param array $dataFields Fields array, containing the POST data fields
     * @param array $expectedFields Fields array, containing the expected fields we should have in POST
     * @param string $intKeyMessage Message string if unexpected found in data fields indexed by int (not protected)
     * @param string $stringKeyMessage Message string if tampered found in
     *  data fields indexed by string (protected).
     * @param string $missingMessage Message string if missing field
     * @return array<string> Messages
     */
    protected function debug_check_fields(array $data_fields, array $expected_fields = [], string $int_key_message = '', string $string_key_message = '', string $missing_message = ''): array
    {
        $messages = $this->match_existing_fields($data_fields, $expected_fields, $int_key_message, $string_key_message);
        $expected_fields_message = $this->debug_expected_fields($expected_fields, $missing_message);
        if ($expected_fields_message !== null) {
            $messages[] = $expected_fields_message;
        }
        return $messages;
    }
    /**
     * Generate array of messages for the existing fields in POST data, matching dataFields in $expectedFields
     * will be unset
     *
     * @param array $dataFields Fields array, containing the POST data fields
     * @param array $expectedFields Fields array, containing the expected fields we should have in POST
     * @param string $intKeyMessage Message string if unexpected found in data fields indexed by int (not protected)
     * @param string $stringKeyMessage Message string if tampered found in
     *   data fields indexed by string (protected)
     * @return array<string> Error messages
     */
    protected function match_existing_fields(array $data_fields, array &$expected_fields, string $int_key_message, string $string_key_message): array
    {
        $messages = [];
        foreach ($data_fields as $key => $value) {
            if (is_int($key)) {
                $found_key = array_search($value, $expected_fields, true);
                if ($found_key === false) {
                    $messages[] = sprintf($int_key_message, $value);
                } else {
                    unset($expected_fields[$found_key]);
                }
            } else {
                if (isset($expected_fields[$key]) && $value !== $expected_fields[$key]) {
                    $messages[] = sprintf($string_key_message, $key, $expected_fields[$key], $value);
                }
                unset($expected_fields[$key]);
            }
        }
        return $messages;
    }
    /**
     * Generate debug message for the expected fields
     *
     * @param array $expectedFields Expected fields
     * @param string $missingMessage Message template
     * @return string|null Error message about expected fields
     */
    protected function debug_expected_fields(array $expected_fields = [], string $missing_message = ''): ?string
    {
        if ($expected_fields === []) {
            return null;
        }
        $expected_field_names = [];
        foreach ($expected_fields as $key => $expected_field) {
            if (is_int($key)) {
                $expected_field_names[] = $expected_field;
            } else {
                $expected_field_names[] = $key;
            }
        }
        return sprintf($missing_message, implode(', ', $expected_field_names));
    }
    /**
     * Return debug info
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['fields' => $this->fields, 'unlockedFields' => $this->unlocked_fields, 'debugMessage' => $this->debug_message];
    }
}