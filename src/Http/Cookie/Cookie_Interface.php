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
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.5.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Cookie;

use DateTimeInterface;
/**
 * Cookie Interface
 */
interface Cookie_Interface
{
    /**
     * Expires attribute format.
     *
     * @var string
     */
    public const EXPIRES_FORMAT = 'D, d-M-Y H:i:s T';
    /**
     * SameSite attribute value: Lax
     *
     * @var string
     */
    public const SAMESITE_LAX = 'Lax';
    /**
     * SameSite attribute value: Strict
     *
     * @var string
     */
    public const SAMESITE_STRICT = 'Strict';
    /**
     * SameSite attribute value: None
     *
     * @var string
     */
    public const SAMESITE_NONE = 'None';
    /**
     * Valid values for "SameSite" attribute.
     *
     * @var array<string>
     */
    public const SAMESITE_VALUES = [self::SAMESITE_LAX, self::SAMESITE_STRICT, self::SAMESITE_NONE];
    /**
     * Sets the cookie name
     *
     * @param string $name Name of the cookie
     */
    public function with_name(string $name): static;
    /**
     * Gets the cookie name
     */
    public function get_name(): string;
    /**
     * Gets the cookie value
     */
    public function get_value(): array|string;
    /**
     * Gets the cookie value as scalar.
     *
     * This will collapse any complex data in the cookie with json_encode()
     */
    public function get_scalar_value(): string;
    /**
     * Create a cookie with an updated value.
     *
     * @param array|string|float|int|bool $value Value of the cookie to set
     */
    public function with_value(array|string|float|int|bool $value): static;
    /**
     * Get the id for a cookie
     *
     * Cookies are unique across name, domain, path tuples.
     */
    public function get_id(): string;
    /**
     * Get the path attribute.
     */
    public function get_path(): string;
    /**
     * Create a new cookie with an updated path
     *
     * @param string $path Sets the path
     */
    public function with_path(string $path): static;
    /**
     * Get the domain attribute.
     */
    public function get_domain(): string;
    /**
     * Create a cookie with an updated domain
     *
     * @param string $domain Domain to set
     */
    public function with_domain(string $domain): static;
    /**
     * Get the current expiry time
     *
     * @return \DateTimeInterface|null Timestamp of expiry or null
     */
    public function get_expiry(): ?DateTimeInterface;
    /**
     * Get the timestamp from the expiration time
     *
     * @return int|null The expiry time as an integer.
     */
    public function get_expires_timestamp(): ?int;
    /**
     * Builds the expiration value part of the header string
     */
    public function get_formatted_expires(): string;
    /**
     * Create a cookie with an updated expiration date
     *
     * @param \DateTimeInterface $dateTime Date time object
     */
    public function with_expiry(DateTimeInterface $date_time): static;
    /**
     * Create a new cookie that will virtually never expire.
     */
    public function with_never_expire(): static;
    /**
     * Create a new cookie that will expire/delete the cookie from the browser.
     *
     * This is done by setting the expiration time to 1 year ago
     */
    public function with_expired(): static;
    /**
     * Check if a cookie is expired when compared to $time
     *
     * Cookies without an expiration date always return false.
     *
     * @param \DateTimeInterface|null $time The time to test against. Defaults to 'now' in UTC.
     */
    public function is_expired(?DateTimeInterface $time = null): bool;
    /**
     * Check if the cookie is HTTP only
     */
    public function is_http_only(): bool;
    /**
     * Create a cookie with HTTP Only updated
     *
     * @param bool $httpOnly HTTP Only
     */
    public function with_http_only(bool $http_only): static;
    /**
     * Check if the cookie is secure
     */
    public function is_secure(): bool;
    /**
     * Create a cookie with Secure updated
     *
     * @param bool $secure Secure attribute value
     */
    public function with_secure(bool $secure): static;
    /**
     * Get the SameSite attribute.
     */
    public function get_same_site(): ?Same_Site_Enum;
    /**
     * Create a cookie with an updated SameSite option.
     *
     * @param \Cake\Http\Cookie\SameSiteEnum|string|null $sameSite Value for to set for Samesite option.
     */
    public function with_same_site(Same_Site_Enum|string|null $same_site): static;
    /**
     * Get cookie options
     *
     * @return array<string, mixed>
     */
    public function get_options(): array;
    /**
     * Get cookie data as array.
     *
     * @return array<string, mixed> With keys `name`, `value`, `expires` etc. options.
     */
    public function to_array(): array;
    /**
     * Returns the cookie as header value
     */
    public function to_header_value(): string;
}