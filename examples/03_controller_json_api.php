<?php

declare(strict_types=1);

/**
 * CakePHP Controller — JSON API controller example.
 *
 * Demonstrates a content-negotiated controller that returns JSON or HTML
 * based on the Accept header, uses viewClasses, and raises typed HTTP
 * exceptions for error cases.
 *
 * This is a reference snippet, not a standalone script — it requires the
 * full CakePHP application bootstrap.
 */

namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\UnprocessableContentException;
use Cake\Http\Response;
use Cake\ORM\Exception\PersistenceFailedException;

/**
 * ArticlesController handles CRUD for the articles resource.
 *
 * Supports content negotiation:
 *   Accept: application/json  → JSON response via JsonView
 *   Accept: text/html         → HTML response via default View
 *
 * @property \App\Model\Table\ArticlesTable $Articles
 */
class ArticlesController extends Controller
{
    /**
     * Register supported view classes for content negotiation.
     *
     * @var array<string>
     */
    protected array $viewClasses = ['Json', 'Xml'];

    /**
     * List all articles, ordered by creation date descending.
     *
     * GET /articles
     * GET /articles.json
     */
    public function index(): Response|null
    {
        $articles = $this->Articles
            ->find()
            ->orderByDesc('created')
            ->limit(20)
            ->all();

        $this->set('articles', $articles);
        $this->viewBuilder()->setOption('serialize', ['articles']);

        return $this->render();
    }

    /**
     * Show a single article by ID.
     *
     * GET /articles/{id}
     *
     * @throws \Cake\Http\Exception\NotFoundException When the article does not exist.
     */
    public function view(int $id): Response|null
    {
        try {
            $article = $this->Articles->get($id, contain: ['Comments', 'Tags']);
        } catch (\Cake\Datasource\Exception\RecordNotFoundException $e) {
            throw new NotFoundException("Article #{$id} was not found.", previous: $e);
        }

        $this->set('article', $article);
        $this->viewBuilder()->setOption('serialize', ['article']);

        return $this->render();
    }

    /**
     * Create a new article from JSON request body.
     *
     * POST /articles.json  Content-Type: application/json
     *
     * @throws \Cake\Http\Exception\UnprocessableContentException On validation failure.
     */
    public function add(): Response|null
    {
        $article = $this->Articles->newEmptyEntity();
        $data    = (array) $this->request->getData();

        $this->Articles->patchEntity($article, $data);

        try {
            $this->Articles->saveOrFail($article);
        } catch (PersistenceFailedException $e) {
            throw new UnprocessableContentException(
                'Validation failed: ' . implode(', ', array_keys($article->getErrors())),
                previous: $e,
            );
        }

        $this->set('article', $article);
        $this->viewBuilder()->setOption('serialize', ['article']);
        $this->response = $this->response->withStatus(201);

        return $this->render();
    }
}
