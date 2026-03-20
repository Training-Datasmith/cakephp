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
 * @since         3.3.12
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\I18n;

/**
 * Translator to translate the message.
 *
 * @internal
 */
class Translator
{
    /**
     * @var string
     */
    public const PLURAL_PREFIX = 'p:';
    /**
     * Constructor
     *
     * @param string $locale The locale being used.
     * @param \Cake\I18n\Package $package The Package containing keys and translations.
     * @param \Cake\I18n\FormatterInterface $formatter A message formatter.
     * @param \Cake\I18n\Translator|null $fallback A fallback translator.
     */
    public function __construct(
        /**
         * The locale being used for translations.
         */
        protected string $locale,
        /**
         * The Package containing keys and translations.
         */
        protected Package $package,
        /**
         * The formatter to use when translating messages.
         */
        protected Formatter_Interface $formatter,
        /**
         * A fallback translator.
         */
        protected ?Translator $fallback = null
    )
    {
    }
    /**
     * Gets the message translation by its key.
     *
     * @param string $key The message key.
     * @return mixed The message translation string, or false if not found.
     */
    protected function get_message(string $key): mixed
    {
        $message = $this->package->get_message($key);
        if ($message) {
            return $message;
        }
        if ($this->fallback) {
            $message = $this->fallback->get_message($key);
            if ($message) {
                $this->package->add_message($key, $message);
                return $message;
            }
        }
        return false;
    }
    /**
     * Translates the message formatting any placeholders
     *
     * @param string $key The message key.
     * @param array $tokensValues Token values to interpolate into the
     *   message.
     * @return string The translated message with tokens replaced.
     */
    public function translate(string $key, array $tokens_values = []): string
    {
        if (isset($tokens_values['_count'])) {
            $message = $this->get_message(static::PLURAL_PREFIX . $key);
            if (!$message) {
                $message = $this->get_message($key);
            }
        } else {
            $message = $this->get_message($key);
            if (!$message) {
                $message = $this->get_message(static::PLURAL_PREFIX . $key);
            }
        }
        if (!$message) {
            // Fallback to the message key
            $message = $key;
        }
        // Check for missing/invalid context
        if (is_array($message) && isset($message['_context'])) {
            $message = $this->resolve_context($key, $message, $tokens_values);
            unset($tokens_values['_context']);
        }
        if (!$tokens_values) {
            // Fallback for plurals that were using the singular key
            if (is_array($message)) {
                return array_values($message + [''])[0];
            }
            return $message;
        }
        // Singular message, but plural call
        if (is_string($message) && isset($tokens_values['_singular'])) {
            $message = [$tokens_values['_singular'], $message];
        }
        // Resolve plural form.
        if (is_array($message)) {
            $count = $tokens_values['_count'] ?? 0;
            $form = Plural_Rules::calculate($this->locale, (int) $count);
            $message = $message[$form] ?? (string) end($message);
        }
        if ($message === '') {
            $message = $key;
            // If singular haven't been translated, fallback to the key.
            if (isset($tokens_values['_singular']) && $tokens_values['_count'] === 1) {
                $message = $tokens_values['_singular'];
            }
        }
        unset($tokens_values['_count'], $tokens_values['_singular']);
        return $this->formatter->format($this->locale, $message, $tokens_values);
    }
    /**
     * Resolve a message's context structure.
     *
     * @param string $key The message key being handled.
     * @param array $message The message content.
     * @param array $vars The variables containing the `_context` key.
     */
    protected function resolve_context(string $key, array $message, array $vars): array|string
    {
        $context = $vars['_context'] ?? null;
        // No or missing context, fallback to the key/first message
        if ($context === null) {
            if (isset($message['_context'][''])) {
                return $message['_context'][''] === '' ? $key : $message['_context'][''];
            }
            return current($message['_context']);
        }
        if (!isset($message['_context'][$context])) {
            return $key;
        }
        if ($message['_context'][$context] === '') {
            return $key;
        }
        return $message['_context'][$context];
    }
    /**
     * Returns the translator package
     */
    public function get_package(): Package
    {
        return $this->package;
    }
}