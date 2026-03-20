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
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\I18n;

use Cake\Cache\Cache_Engine_Interface;
use function Cake\Core\Deprecation_Warning;
use Psr\Simple_Cache\Cache_Interface;
/**
 * Constructs and stores instances of translators that can be
 * retrieved by name and locale.
 */
class Translator_Registry
{
    /**
     * Fallback loader name.
     *
     * @var string
     */
    public const FALLBACK_LOADER = '_fallback';
    /**
     * A registry to retain translator objects.
     *
     * @var array<string, array<string, \Cake\I18n\Translator>>
     */
    protected array $registry = [];
    /**
     * The current locale code.
     */
    protected string $locale;
    /**
     * A list of loader functions indexed by domain name. Loaders are
     * callables that are invoked as a default for building translation
     * packages where none can be found for the combination of translator
     * name and locale.
     *
     * @var array<callable>
     */
    protected array $_loaders = [];
    /**
     * The name of the default formatter to use for newly created
     * translators from the fallback loader
     */
    protected string $_default_formatter = 'default';
    /**
     * Use fallback-domain for translation loaders.
     */
    protected bool $_use_fallback = true;
    /**
     * A CacheEngine object that is used to remember translator across
     * requests.
     *
     * @var (\Psr\SimpleCache\CacheInterface&\Cake\Cache\CacheEngineInterface)|null
     */
    protected $_cacher;
    /**
     * Constructor.
     *
     * @param \Cake\I18n\PackageLocator $packages The package locator.
     * @param \Cake\I18n\FormatterLocator $formatters The formatter locator.
     * @param string $locale The default locale code to use.
     */
    public function __construct(
        /**
         * A package locator.
         */
        protected Package_Locator $packages,
        /**
         * A formatter locator.
         */
        protected Formatter_Locator $formatters,
        string $locale
    )
    {
        $this->set_locale($locale);
        $this->register_loader(static::FALLBACK_LOADER, function ($name, $locale): \Cake\I18n\Package {
            $loader = new Chain_Messages_Loader([new Messages_File_Loader($name, $locale, 'mo'), new Messages_File_Loader($name, $locale, 'po')]);
            $formatter = $name === 'cake' ? 'default' : $this->_default_formatter;
            $package = $loader();
            $package->set_formatter($formatter);
            return $package;
        });
    }
    /**
     * Sets the default locale code.
     *
     * @param string $locale The new locale code.
     */
    public function set_locale(string $locale): void
    {
        $this->locale = $locale;
    }
    /**
     * Returns the default locale code.
     */
    public function get_locale(): string
    {
        return $this->locale;
    }
    /**
     * Returns the translator packages
     */
    public function get_packages(): Package_Locator
    {
        return $this->packages;
    }
    /**
     * An object of type FormatterLocator
     */
    public function get_formatters(): Formatter_Locator
    {
        return $this->formatters;
    }
    /**
     * Sets the CacheEngine instance used to remember translators across
     * requests.
     *
     * @param \Psr\SimpleCache\CacheInterface&\Cake\Cache\CacheEngineInterface $cacher The cacher instance.
     */
    public function set_cacher(Cache_Interface&Cache_Engine_Interface $cacher): void
    {
        $this->_cacher = $cacher;
    }
    /**
     * Gets a translator from the registry by package for a locale.
     *
     * @param string $name The translator package to retrieve.
     * @param string|null $locale The locale to use; if empty, uses the default
     * locale.
     * @return \Cake\I18n\Translator|null A translator object.
     * @throws \Cake\I18n\Exception\I18nException If no translator with that name could be found
     * for the given locale.
     */
    public function get(string $name, ?string $locale = null): ?Translator
    {
        $locale ??= $this->get_locale();
        if (isset($this->registry[$name][$locale])) {
            return $this->registry[$name][$locale];
        }
        if ($this->_cacher === null) {
            return $this->registry[$name][$locale] = $this->_get_translator($name, $locale);
        }
        // Cache keys cannot contain / if they go to file engine.
        $key_name = str_replace('/', '.', $name);
        $key = "translations.{$key_name}.{$locale}";
        /** @var \Cake\I18n\Translator|null $translator */
        $translator = $this->_cacher->get($key);
        if (!$translator) {
            $translator = $this->_get_translator($name, $locale);
            $this->_cacher->set($key, $translator);
        }
        return $this->registry[$name][$locale] = $translator;
    }
    /**
     * Gets a translator from the registry by package for a locale.
     *
     * @param string $name The translator package to retrieve.
     * @param string $locale The locale to use; if empty, uses the default
     * locale.
     * @return \Cake\I18n\Translator A translator object.
     */
    protected function _get_translator(string $name, string $locale): Translator
    {
        if ($this->packages->has($name, $locale)) {
            return $this->create_instance($name, $locale);
        }
        if (isset($this->_loaders[$name])) {
            $package = $this->_loaders[$name]($name, $locale);
        } else {
            $package = $this->_loaders[static::FALLBACK_LOADER]($name, $locale);
        }
        // Support __invoke() wrapper classes
        if (!$package instanceof Package && is_callable($package)) {
            deprecation_warning('5.3.0', 'Using a callable as a package loader is deprecated. ' . 'Please return an instance of \Cake\I18n\Package instead.');
            $package = $package();
        }
        $package = $this->set_fallback_package($name, $package);
        $this->packages->set($name, $locale, $package);
        return $this->create_instance($name, $locale);
    }
    /**
     * Create translator instance.
     *
     * @param string $name The translator package to retrieve.
     * @param string $locale The locale to use; if empty, uses the default locale.
     * @return \Cake\I18n\Translator A translator object.
     */
    protected function create_instance(string $name, string $locale): Translator
    {
        $package = $this->packages->get($name, $locale);
        $fallback = $package->get_fallback();
        if ($fallback !== null) {
            $fallback = $this->get($fallback, $locale);
        }
        $formatter = $this->formatters->get($package->get_formatter());
        return new Translator($locale, $package, $formatter, $fallback);
    }
    /**
     * Registers a loader function for a package name that will be used as a fallback
     * in case no package with that name can be found.
     *
     * Loader callbacks will get as first argument the package name and the locale as
     * the second argument.
     *
     * @param string $name The name of the translator package to register a loader for
     * @param callable $loader A callable object that should return a Package
     */
    public function register_loader(string $name, callable $loader): void
    {
        $this->_loaders[$name] = $loader;
    }
    /**
     * Sets the name of the default messages formatter to use for future
     * translator instances.
     *
     * If called with no arguments, it will return the currently configured value.
     *
     * @param string|null $name The name of the formatter to use.
     * @return string The name of the formatter.
     */
    public function default_formatter(?string $name = null): string
    {
        if ($name === null) {
            return $this->_default_formatter;
        }
        return $this->_default_formatter = $name;
    }
    /**
     * Set if the default domain fallback is used.
     *
     * @param bool $enable flag to enable or disable fallback
     */
    public function use_fallback(bool $enable = true): void
    {
        $this->_use_fallback = $enable;
    }
    /**
     * Set fallback domain for package.
     *
     * @param string $name The name of the package.
     * @param \Cake\I18n\Package $package Package instance
     */
    public function set_fallback_package(string $name, Package $package): Package
    {
        if ($package->get_fallback()) {
            return $package;
        }
        $fallback_domain = null;
        if ($this->_use_fallback && $name !== 'default') {
            $fallback_domain = 'default';
        }
        $package->set_fallback($fallback_domain);
        return $package;
    }
    /**
     * Set domain fallback for loader.
     *
     * @param string $name The name of the loader domain
     * @param callable $loader invokable loader
     * @return callable loader
     */
    public function set_loader_fallback(string $name, callable $loader): callable
    {
        $fallback_domain = 'default';
        if (!$this->_use_fallback || $name === $fallback_domain) {
            return $loader;
        }
        return function () use ($loader, $fallback_domain) {
            /** @var \Cake\I18n\Package $package */
            $package = $loader();
            if (!$package->get_fallback()) {
                $package->set_fallback($fallback_domain);
            }
            return $package;
        };
    }
}