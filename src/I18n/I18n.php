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
namespace Cake\I18n;

use Cake\Cache\Cache;
use Cake\Cache\Exception\InvalidArgumentException;
use function Cake\Core\Deprecation_Warning;
use Cake\I18n\Exception\I18n_Exception;
use Cake\I18n\Formatter\Icu_Formatter;
use Cake\I18n\Formatter\Sprintf_Formatter;
use Locale;
/**
 * I18n handles translation of Text and time format strings.
 */
class I18n
{
    /**
     * Default locale
     *
     * @var string
     */
    public const DEFAULT_LOCALE = 'en_US';
    /**
     * The translators collection
     */
    protected static ?Translator_Registry $_collection = null;
    /**
     * The environment default locale
     */
    protected static ?string $_default_locale = null;
    /**
     * Returns the translators collection instance. It can be used
     * for getting specific translators based on their name and locale
     * or to configure some aspect of future translations that are not yet constructed.
     *
     * @return \Cake\I18n\TranslatorRegistry The translator collection.
     */
    public static function translators(): Translator_Registry
    {
        if (static::$_collection !== null) {
            return static::$_collection;
        }
        static::$_collection = new Translator_Registry(new Package_Locator(), new Formatter_Locator(['default' => Icu_Formatter::class, 'sprintf' => Sprintf_Formatter::class]), static::get_locale());
        if (class_exists(Cache::class)) {
            try {
                $pool = Cache::pool('_cake_translations_');
            } catch (InvalidArgumentException) {
                $pool = Cache::pool('_cake_core_');
                deprecation_warning('5.1.0', 'Cache config `_cake_core_` is deprecated. Use `_cake_translations_` instead');
            }
            static::$_collection->set_cacher($pool);
        }
        return static::$_collection;
    }
    /**
     * Sets a translator.
     *
     * Configures future translators, this is achieved by passing a callable
     * as the last argument of this function.
     *
     * ### Example:
     *
     * ```
     *  I18n::setTranslator('default', function () {
     *      $package = new \Cake\I18n\Package();
     *      $package->setMessages([
     *          'Cake' => 'Gâteau'
     *      ]);
     *      return $package;
     *  }, 'fr_FR');
     *
     *  $translator = I18n::getTranslator('default', 'fr_FR');
     *  echo $translator->translate('Cake');
     * ```
     *
     * You can also use the `Cake\I18n\MessagesFileLoader` class to load a specific
     * file from a folder. For example for loading a `my_translations.po` file from
     * the `resources/locales/custom` folder, you would do:
     *
     * ```
     * I18n::setTranslator(
     *  'default',
     *  new MessagesFileLoader('my_translations', 'custom', 'po'),
     *  'fr_FR'
     * );
     * ```
     *
     * @param string $name The domain of the translation messages.
     * @param callable $loader A callback function or callable class responsible for
     *   constructing a translations package instance.
     * @param string|null $locale The locale for the translator.
     */
    public static function set_translator(string $name, callable $loader, ?string $locale = null): void
    {
        $locale = $locale ?: static::get_locale();
        $translators = static::translators();
        $loader = $translators->set_loader_fallback($name, $loader);
        $packages = $translators->get_packages();
        $packages->set($name, $locale, $loader);
    }
    /**
     * Returns an instance of a translator that was configured for the name and locale.
     *
     * If no locale is passed then it takes the value returned by the `getLocale()` method.
     *
     * @param string $name The domain of the translation messages.
     * @param string|null $locale The locale for the translator.
     * @return \Cake\I18n\Translator The configured translator.
     * @throws \Cake\I18n\Exception\I18nException
     */
    public static function get_translator(string $name = 'default', ?string $locale = null): Translator
    {
        $translators = static::translators();
        $current_locale = null;
        if ($locale) {
            $current_locale = $translators->get_locale();
            $translators->set_locale($locale);
        }
        $translator = $translators->get($name);
        if ($translator === null) {
            throw new I18n_Exception(sprintf('Translator for domain `%s` could not be found.', $name));
        }
        if ($current_locale !== null) {
            $translators->set_locale($current_locale);
        }
        return $translator;
    }
    /**
     * Registers a callable object that can be used for creating new translator
     * instances for the same translations domain. Loaders will be invoked whenever
     * a translator object is requested for a domain that has not been configured or
     * loaded already.
     *
     * Registering loaders is useful when you need to lazily use translations in multiple
     * different locales for the same domain, and don't want to use the built-in
     * translation service based on `gettext` files.
     *
     * Loader objects will receive two arguments: The domain name that needs to be
     * built, and the locale that is requested. These objects can assemble the messages
     * from any source, but must return an `Cake\I18n\Package` object.
     *
     * ### Example:
     *
     * ```
     *  use Cake\I18n\MessagesFileLoader;
     *  I18n::config('my_domain', function ($name, $locale) {
     *      // Load resources/locales/$locale/filename.po
     *      $fileLoader = new MessagesFileLoader('filename', $locale, 'po');
     *      return $fileLoader();
     *  });
     * ```
     *
     * You can also assemble the package object yourself:
     *
     * ```
     *  use Cake\I18n\Package;
     *  I18n::config('my_domain', function ($name, $locale) {
     *      $package = new Package('default');
     *      $messages = (...); // Fetch messages for locale from external service.
     *      $package->setMessages($message);
     *      $package->setFallback('default');
     *      return $package;
     *  });
     * ```
     *
     * @param string $name The name of the translator to create a loader for
     * @param callable $loader A callable object that should return a Package
     * instance to be used for assembling a new translator.
     */
    public static function config(string $name, callable $loader): void
    {
        static::translators()->register_loader($name, $loader);
    }
    /**
     * Sets the default locale to use for future translator instances.
     * This also affects the `intl.default_locale` PHP setting.
     *
     * @param string $locale The name of the locale to set as default.
     */
    public static function set_locale(string $locale): void
    {
        static::get_default_locale();
        Locale::set_default($locale);
        if (isset(static::$_collection)) {
            static::translators()->set_locale($locale);
        }
    }
    /**
     * Will return the currently configured locale as stored in the
     * `intl.default_locale` PHP setting.
     *
     * @return string The name of the default locale.
     */
    public static function get_locale(): string
    {
        static::get_default_locale();
        $current = Locale::get_default();
        if ($current === '') {
            $current = static::DEFAULT_LOCALE;
            Locale::set_default($current);
        }
        return $current;
    }
    /**
     * Returns the default locale.
     *
     * This returns the default locale before any modifications, i.e.
     * the value as stored in the `intl.default_locale` PHP setting before
     * any manipulation by this class.
     */
    public static function get_default_locale(): string
    {
        return static::$_default_locale ??= Locale::get_default() ?: static::DEFAULT_LOCALE;
    }
    /**
     * Returns the currently configured default formatter.
     *
     * @return string The name of the formatter.
     */
    public static function get_default_formatter(): string
    {
        return static::translators()->default_formatter();
    }
    /**
     * Sets the name of the default messages formatter to use for future
     * translator instances. By default, the `default` and `sprintf` formatters
     * are available.
     *
     * @param string $name The name of the formatter to use.
     */
    public static function set_default_formatter(string $name): void
    {
        static::translators()->default_formatter($name);
    }
    /**
     * Set if the domain fallback is used.
     *
     * @param bool $enable flag to enable or disable fallback
     */
    public static function use_fallback(bool $enable = true): void
    {
        static::translators()->use_fallback($enable);
    }
    /**
     * Destroys all translator instances and creates a new empty translations
     * collection.
     */
    public static function clear(): void
    {
        static::$_collection = null;
    }
}