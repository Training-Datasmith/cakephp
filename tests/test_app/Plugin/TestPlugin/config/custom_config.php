<?php

declare(strict_types=1);

namespace TestPlugin\Config;

use Cake\Core\Configure;

Configure::write('PluginTest.test_plugin.custom', 'loaded plugin custom config');
