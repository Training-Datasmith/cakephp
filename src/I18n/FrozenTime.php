<?php

declare (strict_types=1);
use function Cake\Core\Deprecation_Warning;
use Cake\I18n\DateTime;
deprecation_warning('5.0.0', 'Cake\I18n\FrozenTime is deprecated. Use Cake\I18n\DateTime instead');
class_exists(DateTime::class);