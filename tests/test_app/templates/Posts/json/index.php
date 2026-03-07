<?php

declare(strict_types=1);
$paging = $this->Paginator->options['url'] ?? null;

$formatted = [
    'user' => $user['User']['username'],
    'list' => [],
    'paging' => $paging,
];
foreach ($user['Item'] as $item) {
    $formatted['list'][] = $item['name'];
}

echo json_encode($formatted);
