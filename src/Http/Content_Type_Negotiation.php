<?php

declare (strict_types=1);
namespace Cake\Http;

use Psr\Http\Message\Request_Interface;
/**
 * Negotiates the preferred content type from what the application
 * provides and what the request has in its Accept header.
 */
class Content_Type_Negotiation
{
    /**
     * Parse Accept* headers with qualifier options.
     *
     * Only qualifiers will be extracted, any other accept extensions will be
     * discarded as they are not frequently used.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request to get an accept from.
     * @return array<string, array<string>> A mapping of preference values => content types
     */
    public function parse_accept(Request_Interface $request): array
    {
        $header = $request->get_header_line('Accept');
        return $this->parse_qualifiers($header);
    }
    /**
     * Parse the Accept-Language header
     *
     * Only qualifiers will be extracted, other extensions will be ignored
     * as they are not frequently used.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request to get an accept from.
     * @return array<string, array<string>> A mapping of preference values => languages
     */
    public function parse_accept_language(Request_Interface $request): array
    {
        $header = $request->get_header_line('Accept-Language');
        return $this->parse_qualifiers($header);
    }
    /**
     * Parse a header value into preference => value mapping
     *
     * @param string $header The header value to parse
     * @return array<string, array<string>>
     */
    protected function parse_qualifiers(string $header): array
    {
        return Header_Utility::parse_accept($header);
    }
    /**
     * Get the most preferred content type from a request.
     *
     * Parse the Accept header preferences and return the most
     * preferred type. If multiple types are tied in preference
     * the first type of that preference value will be returned.
     *
     * You can expect null when the request has no Accept header.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request to use.
     * @param array<string> $choices The supported content type choices.
     * @return string|null The preferred type or null if there is no match with choices or if the
     *   request had no Accept header.
     */
    public function preferred_type(Request_Interface $request, array $choices = []): ?string
    {
        $parsed = $this->parse_accept($request);
        if (!$parsed) {
            return null;
        }
        if (!$choices) {
            $preferred = array_shift($parsed);
            return $preferred[0];
        }
        foreach ($parsed as $accept_types) {
            $common = array_intersect($accept_types, $choices);
            if ($common) {
                return array_shift($common);
            }
        }
        return null;
    }
    /**
     * Get the normalized list of accepted languages
     *
     * Language codes in the request will be normalized to lower case and have
     * `_` replaced with `-`.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request to read headers from.
     * @return array<string> A list of language codes that are accepted.
     */
    public function accepted_languages(Request_Interface $request): array
    {
        $raw = $this->parse_accept_language($request);
        $accept = [];
        foreach ($raw as $languages) {
            foreach ($languages as &$lang) {
                if (strpos($lang, '_')) {
                    $lang = str_replace('_', '-', $lang);
                }
                $lang = strtolower($lang);
            }
            $accept = array_merge($accept, $languages);
        }
        return $accept;
    }
    /**
     * Check if the request accepts a given language code.
     *
     * Language codes in the request will be normalized to lower case and have `_` replaced
     * with `-`.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request to read headers from.
     * @param string $lang The language code to check.
     * @return bool Whether the request accepts $lang
     */
    public function accept_language(Request_Interface $request, string $lang): bool
    {
        $accept = $this->accepted_languages($request);
        return in_array(strtolower($lang), $accept, true);
    }
}