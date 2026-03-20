<?php

declare (strict_types=1);
namespace Cake\Http;

/**
 * Provides helper methods related to HTTP headers
 */
class Header_Utility
{
    /**
     * Get an array representation of the HTTP Link header values.
     *
     * @param array $linkHeaders An array of Link header strings.
     */
    public static function parse_links(array $link_headers): array
    {
        $result = [];
        foreach ($link_headers as $link_header) {
            $result[] = static::parse_link_item($link_header);
        }
        return $result;
    }
    /**
     * Parses one item of the HTTP link header into an array
     *
     * @param string $value The HTTP Link header part
     * @return array<string, mixed>
     */
    protected static function parse_link_item(string $value): array
    {
        preg_match('/<(.*)>[; ]?[; ]?(.*)?/i', $value, $matches);
        if ($matches === []) {
            return [];
        }
        $url = $matches[1];
        $parsed_params = ['link' => $url];
        $params = $matches[2];
        if (!$params) {
            return $parsed_params;
        }
        $exploded_params = explode(';', $params);
        foreach ($exploded_params as $param) {
            $exploded_param = explode('=', $param);
            $trimmed_key = trim($exploded_param[0]);
            $trimmed_value = trim($exploded_param[1], '"');
            if ($trimmed_key === 'title*') {
                // See https://www.rfc-editor.org/rfc/rfc8187#section-3.2.3
                preg_match("/(.*)'(.*)'(.*)/i", $trimmed_value, $matches);
                assert(!empty($matches[1]) && !empty($matches[2]) && !empty($matches[3]));
                $trimmed_value = ['language' => $matches[2], 'encoding' => $matches[1], 'value' => urldecode($matches[3])];
            }
            $parsed_params[$trimmed_key] = $trimmed_value;
        }
        return $parsed_params;
    }
    /**
     * Parse the Accept header value into weight => value mapping.
     *
     * @param string $header The header value to parse
     * @return array<string, array<string>>
     */
    public static function parse_accept(string $header): array
    {
        $accept = [];
        if (!$header) {
            return $accept;
        }
        $headers = explode(',', $header);
        foreach (array_filter($headers) as $value) {
            $pref_value = '1.0';
            $value = trim($value);
            $semi_pos = strpos($value, ';');
            if ($semi_pos !== false) {
                $params = explode(';', $value);
                $value = trim($params[0]);
                foreach ($params as $param) {
                    $q_pos = strpos($param, 'q=');
                    if ($q_pos !== false) {
                        $pref_value = substr($param, $q_pos + 2);
                    }
                }
            }
            $accept[$pref_value] ??= [];
            if ($pref_value) {
                $accept[$pref_value][] = $value;
            }
        }
        krsort($accept);
        return $accept;
    }
    /**
     * @param string $value The WWW-Authenticate header
     */
    public static function parse_www_authenticate(string $value): array
    {
        preg_match_all('@(\w+)=(?:(?:")([^"]+)"|([^\s,$]+))@', $value, $matches, PREG_SET_ORDER);
        $return = [];
        foreach ($matches as $match) {
            /** @phpstan-ignore-next-line */
            $return[$match[1]] = $match[3] ?? $match[2];
        }
        return $return;
    }
}