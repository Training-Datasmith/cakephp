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
 * @link          https://book.cakephp.org/5/en/development/errors.html#configuration
 * @since         4.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Console\Exception;

use Throwable;
/**
 * Exception raised with suggestions
 */
class Missing_Option_Exception extends Console_Exception
{
    /**
     * Constructor.
     *
     * @param string $message The string message.
     * @param string $requested The requested value.
     * @param array<string> $suggestions The list of potential values that were valid.
     * @param int|null $code The exception code if relevant.
     * @param \Throwable|null $previous the previous exception.
     */
    public function __construct(
        string $message,
        /**
         * The requested thing that was not found.
         */
        protected string $requested = '',
        /**
         * The valid suggestions.
         */
        protected array $suggestions = [],
        ?int $code = null,
        ?Throwable $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }
    /**
     * Get the message with suggestions
     */
    public function get_full_message(): string
    {
        $out = $this->get_message();
        $best_guess = $this->find_closest_item($this->requested, $this->suggestions);
        if ($best_guess) {
            $out .= "\nDid you mean: `{$best_guess}`?";
        }
        $good = [];
        foreach ($this->suggestions as $option) {
            if (levenshtein($option, $this->requested) < 8) {
                $good[] = '- ' . $option;
            }
        }
        if ($good) {
            $out .= "\n\nOther valid choices:\n\n" . implode("\n", $good);
        }
        return $out;
    }
    /**
     * Find the best match for requested in suggestions
     *
     * @param string $needle Unknown option name trying to be used.
     * @param array<string> $haystack Suggestions to look through.
     * @return string|null The best match
     */
    protected function find_closest_item(string $needle, array $haystack): ?string
    {
        $best_guess = null;
        foreach ($haystack as $item) {
            if (str_starts_with($item, $needle)) {
                return $item;
            }
        }
        $best_score = 4;
        foreach ($haystack as $item) {
            $score = levenshtein($needle, $item);
            if ($score < $best_score) {
                $best_score = $score;
                $best_guess = $item;
            }
        }
        return $best_guess;
    }
}