<?php

declare(strict_types=1);
$content = explode("\n", $content);

foreach ($content as $line):
    echo '<p> ' . $line . '</p>';
endforeach;
