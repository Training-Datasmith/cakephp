# CakePHP Framework Architecture

## Purpose

CakePHP is a rapid-development PHP framework following the Model-View-Controller (MVC) pattern. It provides
convention-over-configuration tooling for building web applications, including an ORM, HTTP abstractions,
routing, validation, and an event system.

## Directory Structure

```
src/
  Controller/        Application controllers — map HTTP requests to business logic
    Component/       Reusable controller plugins (Flash, FormProtection, CheckHttpCache)
    Exception/       HTTP and security exceptions thrown during dispatch
  Http/              PSR-7/PSR-15 HTTP layer — requests, responses, middleware, cookies
    Client/          HTTP client for outbound requests (cURL/stream adapters)
    Cookie/          Typed cookie value objects and SameSite enum
    Exception/       HTTP status exceptions (404, 403, 500, …)
    Middleware/       Pipeline middleware: CSRF, CORS, CSP, rate limiting, body parsing
    RateLimit/        Fixed-window, sliding-window and token-bucket limiters
    Session/          Session handlers (database-backed, cache-backed)
  ORM/               Object-relational mapper — tables, entities, associations, queries
    Association/      BelongsTo, HasOne, HasMany, BelongsToMany relationship types
    Behavior/         Pluggable behaviors: Timestamp, CounterCache, Translate, Tree
    Exception/        Persistence and integrity exceptions
    Locator/          Table registry and locator (TableLocator)
    Query/            Typed query builders: SelectQuery, InsertQuery, UpdateQuery, DeleteQuery
    Rule/             Application-level integrity rules (IsUnique, ExistsIn, ValidCount)
```

## Key Design Decisions

- **Convention over configuration**: table names, entity class names, and association aliases are
  derived automatically from class names via `Inflector`, reducing boilerplate.
- **Immutable PSR-7 messages**: `Request` and `Response` extend PSR-7 interfaces; mutations return
  new instances so middleware can chain transformations safely.
- **Event-driven lifecycle**: `Table` fires `Model.beforeSave`, `Model.afterSave`, etc. via
  `EventDispatcherTrait`. Controllers fire `Controller.beforeFilter`, `Controller.afterFilter`.
- **Layered middleware pipeline**: `Server` feeds requests through a `MiddlewareQueue` before
  reaching the controller factory, enabling cross-cutting concerns without inheritance.
- **Query factory pattern**: `QueryFactory` produces typed query objects so callers receive
  `SelectQuery`, `InsertQuery`, etc. rather than a generic `Query` — enabling return-type inference.

## Extension Points

| Mechanism | How to extend |
|-----------|---------------|
| Custom Table | Extend `Cake\ORM\Table`, override `initialize()` |
| Custom Behavior | Extend `Cake\ORM\Behavior`, add to table via `addBehavior()` |
| Custom Middleware | Implement `Psr\Http\Server\MiddlewareInterface`, add via `Application::middleware()` |
| Custom Exception Renderer | Configure `Error.exceptionRenderer` in `config/app.php` |
| Custom Validation | Override `validationDefault()` on a Table; add rules via `RulesChecker` |
| Custom Query Finder | Add `findXxx(SelectQuery $query, mixed ...$args)` method on a Table |

## Dependency Flow

```
HTTP Server
  └─ MiddlewareQueue (CSRF, CSP, BodyParser, …)
       └─ ControllerFactory
            └─ Controller
                 └─ Table (ORM)
                      ├─ Connection (Database)
                      ├─ BehaviorRegistry
                      └─ AssociationCollection
```

## Key Classes

- `Cake\Http\Server` — bootstraps the application and runs the middleware pipeline
- `Cake\Controller\Controller` — base controller; dispatches to action methods
- `Cake\ORM\Table` — central ORM class; handles find, save, delete with full event lifecycle
- `Cake\ORM\Entity` — data container returned by ORM queries; tracks dirty fields
- `Cake\Http\ServerRequest` — immutable incoming request wrapping PSR-7 + CakePHP helpers
- `Cake\Http\Response` — immutable outgoing response with cookie/status/body helpers
