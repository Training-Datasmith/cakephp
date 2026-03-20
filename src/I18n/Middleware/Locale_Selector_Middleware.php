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
 * @since         3.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\I18n\Middleware;

use Cake\I18n\I18n;
use Locale;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Server\Middleware_Interface;
use Psr\Http\Server\Request_Handler_Interface;
/**
 * Sets the runtime default locale for the request based on the
 * Accept-Language header. The default will only be set if it
 * matches the list of passed valid locales.
 */
class Locale_Selector_Middleware implements Middleware_Interface
{
    /**
     * Constructor.
     *
     * @param array $locales A list of accepted locales, or ['*'] to accept any
     *   locale header value.
     */
    public function __construct(
        /**
         * List of valid locales for the request
         */
        protected array $locales = []
    )
    {
    }
    /**
     * Set locale based on request headers.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function process(Server_Request_Interface $request, Request_Handler_Interface $handler): Response_Interface
    {
        $locale = Locale::accept_from_http($request->get_header_line('Accept-Language'));
        if (!$locale) {
            return $handler->handle($request);
        }
        if ($this->locales !== ['*']) {
            $locale = Locale::lookup($this->locales, $locale, true);
        }
        if ($locale) {
            I18n::set_locale($locale);
        }
        return $handler->handle($request);
    }
}