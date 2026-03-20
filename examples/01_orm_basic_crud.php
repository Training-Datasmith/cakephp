<?php

declare(strict_types=1);

/**
 * CakePHP ORM — basic CRUD example.
 *
 * Demonstrates creating, reading, updating and deleting records using the
 * CakePHP ORM without the full framework bootstrap (uses a standalone
 * connection configuration).
 *
 * Prerequisites:
 *   composer require cakephp/orm
 *
 * Run:
 *   php examples/01_orm_basic_crud.php
 */

use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;

// 1. Configure a database connection.
ConnectionManager::setConfig('default', [
    'className'  => 'Cake\Database\Connection',
    'driver'     => 'Cake\Database\Driver\Mysql',
    'host'       => '127.0.0.1',
    'username'   => 'root',
    'password'   => '',
    'database'   => 'blog',
    'encoding'   => 'utf8mb4',
    'timezone'   => 'UTC',
    'cacheMetadata' => true,
]);

// 2. Obtain a Table instance from the registry (auto-configured by convention).
$articles = TableRegistry::getTableLocator()->get('Articles');

// 3. CREATE — build and persist a new entity.
$article = $articles->newEmptyEntity();
$article->title   = 'Hello CakePHP ORM';
$article->body    = 'This is a standalone ORM example.';
$article->user_id = 1;

if ($articles->save($article)) {
    echo "Saved article #{$article->id}\n";
} else {
    echo "Save failed:\n";
    print_r($article->getErrors());
}

// 4. READ — find a single record by primary key.
$found = $articles->get($article->id, contain: ['Users']);
echo "Title: {$found->title}, Author: {$found->user->username}\n";

// 5. UPDATE — patch and re-save.
$articles->patchEntity($found, ['title' => 'Updated Title']);
$articles->save($found);
echo "Updated title: {$found->title}\n";

// 6. DELETE — remove the record.
$articles->delete($found);
echo "Article deleted.\n";

// 7. FIND — paginated list with conditions.
$recent = $articles->find()
    ->where(['Articles.created >' => new \DateTime('-7 days')])
    ->orderByDesc('Articles.created')
    ->limit(5)
    ->all();

echo "Recent articles (last 7 days):\n";
foreach ($recent as $row) {
    echo "  [{$row->id}] {$row->title}\n";
}
