# Entity support (`Project\Support\Entity\Entity`)

An enterprise-grade, **GDA-safe** base class for every domain entity — the
refactored core of the legacy `__DEV__/Entity` Active Record, with all the
persistence/ORM machinery stripped out and a hardened, secure feature set added.

- **Namespace:** `Project\Support\Entity`
- **Autoload:** `Project\` → `projects/` (PSR-4, already wired in `composer.json`)
- **Pairs with:** [`Project\Support\Casting\DataCaster`](../Casting/README.md) (the `$casts` engine)
  and `Project\Support\Hydration\DataConverter` (row ⇄ object mapping)

It performs **no I/O**, reads **no globals** (`app()`/`kernel()`/`config()`), and
imports only the sibling casting utility — so it is safe to extend from a
plugin's `Domain/` layer without violating the Five Access Rules.

---

## Table of contents

1. [Why it was decomposed, not relocated](#why-it-was-decomposed-not-relocated)
2. [What it keeps vs. removes](#what-it-keeps-vs-removes)
3. [Quick start](#quick-start)
4. [Configuration properties](#configuration-properties)
5. [Full API reference](#full-api-reference)
6. [Type casting (`$casts`)](#type-casting-casts)
7. [Accessors & mutators](#accessors--mutators)
8. [Security model](#security-model)
9. [Serialization](#serialization)
10. [Change tracking](#change-tracking)
11. [Domain events](#domain-events)
12. [Immutability sealing](#immutability-sealing)
13. [Use in the GDA layers](#use-in-the-gda-layers)
14. [Cookbook — exhaustive examples](#cookbook--exhaustive-examples)
15. [Design notes & caveats](#design-notes--caveats)

---

## Why it was decomposed, not relocated

`__DEV__/Entity/Entity.php` is a CodeIgniter/Eloquent-style **fat Active Record**:
magic `__get/__set`, mutators/accessors, `save()/delete()/restore()`,
`performInsert/Update`, `getRepo_()`, WP-style meta tables and change tracking —
all in one base. GDA explicitly forbids this (no ORM/AR in the Domain layer, no
entity importing infrastructure, no entity calling its own repository).

So the single class was split across the layers it conflated. This base keeps
only the **pure, infrastructure-free entity mechanics**; persistence and request
validation move to their proper homes.

## What it keeps vs. removes

| Kept (pure entity mechanics) | Removed (moved to its GDA home) |
| --- | --- |
| attribute bag + change tracking | `save()` / `delete()` / `restore()` → **Repository** (`DatabasePort`) |
| type casting via `DataCaster` (`$casts`) | `performInsert/Update`, meta tables, `getRepo_()` → **Repository** |
| `get{X}Attribute` / `set{X}Attribute` hooks | magic `__get` DB fallback → gone (entities never query) |
| domain-event buffer | `app()` / `kernel()` global lookups → gone |
| `reconstitute()` / `toRawArray()` (Hydrator seam) | — |
| mass-assignment guard (kept as a defense-in-depth safety net) | (primary validation still belongs in the DTO at the controller edge) |

---

## Quick start

```php
use Project\Support\Entity\Entity;

final class User extends Entity
{
    protected string $primaryKey = 'id';

    protected array $casts = [
        'id'        => 'int',
        'active'    => 'bool',
        'roles'     => '?json[array]',
        'createdAt' => 'datetime',
    ];

    protected array $fillable = ['name', 'email', 'active', 'roles'];
    protected array $hidden   = ['password'];      // never serialized / dumped
    protected array $appends  = ['display'];       // computed, added to output
    protected array $dates    = ['createdAt'];

    // Named constructor — records a creation event
    public static function register(string $name, string $email): self
    {
        $u = (new self())->fill(['name' => $name, 'email' => $email, 'active' => true]);
        $u->recordEvent(new UserRegistered($email));
        return $u;
    }

    // Computed accessor surfaced via $appends
    public function getDisplayAttribute(): string
    {
        return strtoupper($this->getString('name'));
    }
}
```

```php
$user = User::register('ada', 'ada@example.com');
$user->getBool('active');      // true
$user->toArray();              // ['name'=>'ada', ..., 'display'=>'ADA']  (no 'password')
foreach ($user->releaseEvents() as $event) { /* hand to the collector */ }
```

---

## Configuration properties

Override these `protected` properties in your subclass:

| Property | Type | Default | Purpose |
| --- | --- | --- | --- |
| `$primaryKey` | `string` | `'id'` | Key field used by `getKey()`/`exists()`/`is()` |
| `$casts` | `array<string,string>` | `[]` | Field → cast type (see [Type casting](#type-casting-casts)) |
| `$customCasters` | `array<string,class-string>` | `[]` | Extra cast handlers `[type => CastInterface]` |
| `$fillable` | `list<string>` | `[]` | Mass-assignment whitelist |
| `$guarded` | `list<string>` | `['*']` | Mass-assignment blacklist (default: deny all) |
| `$hidden` | `list<string>` | `[]` | Excluded from array/JSON **and** redacted in dumps |
| `$visible` | `list<string>` | `[]` | If set, ONLY these appear in array/JSON |
| `$appends` | `list<string>` | `[]` | Computed accessor names added to output |
| `$dates` | `list<string>` | `[]` | Fields serialized via `$dateFormat` |
| `$dateFormat` | `string` | `'Y-m-d H:i:s'` | Date serialization format |

---

## Full API reference

### Construction / lifecycle

| Method | Returns | Notes |
| --- | --- | --- |
| `new static(?array $attributes = null)` | — | Raw hydration; **bypasses** guards; syncs original |
| `static::make()` | `static` | Blank instance |
| `static::reconstitute(array $row)` | `static` | Hydrate from a DB row; **records no events** |
| `replicate(array $except = [])` | `static` | Copy **without** the primary key (and `$except`) |
| `__clone()` | — | Resets change-tracking, events and seal |

### Mass assignment

| Method | Returns | Notes |
| --- | --- | --- |
| `fill(array $data)` | `static` | Writes only fillable keys (safe) |
| `forceFill(array $data)` | `static` | Bypasses guards — trusted data only |
| `isFillable(string $key)` | `bool` | Guard evaluation |

### Attribute access

| Method | Returns |
| --- | --- |
| `getAttribute(string $key)` | cast + accessor-applied value |
| `setAttribute(string $key, $value)` | `static` (mutator + cast applied) |
| `getRawAttribute(string $key)` | uncast stored value |
| `hasAttribute(string $key)` | `bool` |
| `only(array $keys)` / `except(array $keys)` | `array` |

### Typed, null-safe getters

| Method | Returns |
| --- | --- |
| `getString($key, $default='')` | `string` |
| `getInt($key, $default=0)` | `int` |
| `getFloat($key, $default=0.0)` | `float` |
| `getBool($key, $default=false)` | `bool` |
| `getArray($key, $default=[])` | `array` |
| `getDate($key)` | `?DateTimeImmutable` |

### Serialization methods

| Method | Returns |
| --- | --- |
| `toArray(bool $onlyChanged=false)` | visibility-filtered, cast, appends + dates |
| `toRawArray(bool $onlyChanged=false)` | raw DataSource-shaped attributes |
| `jsonSerialize()` | `array` (= `toArray()`) |
| `toJson(int $flags=0)` | `string` (throws on encode error) |
| `__toString()` | JSON |
| `makeHidden($keys)` / `makeVisible($keys)` | `static` |

### Change-tracking methods

| Method | Returns |
| --- | --- |
| `syncOriginal()` | `static` — snapshot current state |
| `isDirty(...$keys)` | `bool` |
| `isClean(...$keys)` | `bool` |
| `wasChanged(...$keys)` | `bool` (alias of `isDirty`) |
| `getChanges()` / `getDirty()` | `array` of changed fields |
| `getOriginal(?string $key=null, $default=null)` | snapshot value(s) |

### Identity helpers

| Method | Returns |
| --- | --- |
| `getKey()` | primary key value |
| `getKeyName()` | key field name |
| `exists()` | `bool` (non-empty key) |
| `is(?Entity $other)` / `isNot(?Entity $other)` | `bool` (same class + key) |

### Domain-event methods

| Method | Returns |
| --- | --- |
| `recordEvent(object $event)` | `void` (`protected`) |
| `hasEvents()` | `bool` |
| `releaseEvents()` | `list<object>` (returns **and clears**) |

### Immutability

| Method | Returns |
| --- | --- |
| `seal()` | `static` — lock the bag |
| `isSealed()` | `bool` |

### Interfaces implemented

`JsonSerializable`, `ArrayAccess` (`$entity['field']`), `Stringable`.

---

## Type casting (`$casts`)

Casting is bidirectional and runs through `DataCaster`:

- **read** (`getAttribute`/`toArray`) → `get` direction (DataSource → PHP)
- **write** (`setAttribute`) → `set` direction (PHP → DataSource)

```php
protected array $casts = [
    'id'     => 'int',
    'price'  => 'float',
    'active' => 'bool',
    'flags'  => 'int-bool',     // bool in PHP, 0/1 in the column
    'tags'   => 'csv',
    'meta'   => '?json[array]', // ? = nullable, [array] = decode assoc
    'opened' => 'datetime',     // datetime[ms] / datetime[us] / datetime[Y-m-d]
];
```

Built-in types: `int|integer`, `float|double`, `string`, `bool|boolean`,
`int-bool`, `csv`, `array`, `json`, `object`, `datetime`, `timestamp`.
Register custom ones via `$customCasters` (must implement
`Project\Support\Casting\CastInterface`). Full grammar:
[Casting README](../Casting/README.md).

> `'bool'` casts only on **read**; use `'int-bool'` when the column stores `0/1`
> and you want `toRawArray()` to emit an int.

---

## Accessors & mutators

Define `get{Studly}Attribute($value)` / `set{Studly}Attribute($value)` to hook a
single field. Studly conversion handles `snake_case`, `kebab-case` and spaces.

```php
public function getNameAttribute($v): string { return ucfirst((string) $v); }
public function setEmailAttribute($v): string { return strtolower(trim((string) $v)); }
```

Accessors run **after** casting on read; mutators run **before** casting on write.
Method existence is memoized per class for performance.

---

## Security model

**Mass assignment is denied by default.**

```php
protected array $guarded = ['*'];   // nothing mass-assignable…
protected array $fillable = ['name', 'email'];   // …except these
```

```php
$user->fill($request->all());   // 'id', 'is_admin', 'password' silently dropped
$user->forceFill($trusted);     // bypass — ONLY for internal, trusted data
```

This is **defense in depth**: the DTO at the controller edge is still the primary
validator; the entity guard is the second line so over-posting can never reach
the attribute bag.

**Secrets never leak into logs.** `__debugInfo()` redacts every `$hidden` field
as `********`, so `var_dump($entity)`, stack traces and error dumps stay safe:

```php
protected array $hidden = ['password', 'api_token'];
// var_dump($user) → ['password' => '********', 'api_token' => '********', ...]
```

**Read-only snapshots.** `seal()` makes the bag immutable — any
`set`/`__set`/`offsetSet`/`unset` throws `LogicException`. Use for cached
projections shared within a request so accidental writes are impossible.

---

## Serialization

`toArray()` / `jsonSerialize()` / `toJson()` apply, in order:

1. **Visibility** — drop `$hidden`; if `$visible` is set, keep only those.
2. **Casting** — each value via its `$casts` entry.
3. **Date formatting** — `$dates` fields via `$dateFormat`; any
   `DateTimeInterface` value is formatted; nested `JsonSerializable` is unwrapped.
4. **Appends** — each `$appends` accessor (subject to visibility).

`toRawArray()` returns the **raw** stored attributes (DataSource shape) for
persistence — let the `DataConverter` apply row-level casts if you want a fully
typed raw array.

---

## Change tracking

```php
$user->syncOriginal();          // baseline (Repository calls this after load/save)
$user->name = 'grace';
$user->isDirty();               // true
$user->isDirty('email');        // false
$user->wasChanged('name');      // true
$user->getDirty();              // ['name' => 'grace']
$user->getOriginal('name');     // 'ada'
```

A Repository typically persists only `toRawArray(onlyChanged: true)` and calls
`syncOriginal()` after a successful write.

---

## Domain events

Entities **record** events during state changes; the **Service** flushes them
inside the transaction/commit pattern — the entity never dispatches.

```php
public function deactivate(): void
{
    if (! $this->getBool('active')) {
        throw new \DomainException('User already inactive');
    }
    $this->active = false;
    $this->recordEvent(new UserDeactivated($this->getKey()));
}
```

```php
// In the Service:
$user->deactivate();
foreach ($user->releaseEvents() as $event) {
    $this->collector->collect($event);   // buffered in-tx, discarded on rollback
}
$this->repository->save($user);
```

`reconstitute()` (hydration) records **no** events.

---

## Immutability sealing

```php
$snapshot = User::reconstitute($row)->seal();
$snapshot->name;          // ✅ read freely
$snapshot->name = 'x';    // ❌ throws LogicException
$snapshot->isSealed();    // true
$copy = clone $snapshot;  // clone is unsealed + tracking reset
```

---

## Use in the GDA layers

```php
// ── Domain entity ── extends this base, no infrastructure imports
final class Invoice extends Entity { /* $casts, named ctors, transitions */ }

// ── Service ── transaction + event pattern
$invoice->pay();
foreach ($invoice->releaseEvents() as $e) {
    $this->collector->collect($e);
}
$this->repository->save($invoice);

// ── Repository ── the ONLY place that touches the DB (DatabasePort)
public function find(string $id): Invoice
{
    $row = $this->db->queryOne(
        'SELECT * FROM invoices WHERE id = :id AND tenant_id = :t',
        ['id' => $id, 't' => $this->identity->tenantId]
    ) ?? throw new RepositoryException("Invoice [{$id}] not found", layer: 'repository.invoice');

    return Invoice::reconstitute($row);          // or via DataConverter to apply casts
}

public function save(Invoice $invoice): void
{
    $this->db->upsert('invoices', $invoice->toRawArray(onlyChanged: true), ['id']);
    $invoice->syncOriginal();
}
```

For automatic row-level casting through the hydrator, see the `DataConverter`
example in the [Casting README](../Casting/README.md).

---

## Cookbook — exhaustive examples

A copy-pasteable reference for every feature. Each block is self-contained.

### 1. Defining an entity

```php
use Project\Support\Entity\Entity;

final class Article extends Entity
{
    protected string $primaryKey = 'id';

    protected array $casts = [
        'id'          => 'int',
        'published'   => 'bool',
        'views'       => 'int',
        'rating'      => 'float',
        'tags'        => 'csv',
        'meta'        => '?json[array]',
        'publishedAt' => '?datetime',
    ];

    protected array $fillable = ['title', 'body', 'tags', 'published'];
    protected array $hidden   = ['authorEmail'];
    protected array $appends  = ['excerpt'];
    protected array $dates    = ['publishedAt'];

    public function getExcerptAttribute(): string
    {
        return mb_substr($this->getString('body'), 0, 80);
    }
}
```

### 2. Every cast type, round-tripped

```php
$e = new class extends Entity {
    protected array $casts = [
        'n'   => 'int',
        'amt' => 'float',
        's'   => 'string',
        'b'   => 'bool',
        'ib'  => 'int-bool',     // bool in PHP, 0/1 in DB
        'csv' => 'csv',
        'arr' => 'array',        // PHP serialize() in DB
        'j'   => 'json',
        'ja'  => 'json[array]',  // decode as assoc array
        'o'   => 'object',
        'dt'  => 'datetime',
        'ts'  => 'timestamp',
    ];
};

$e->n   = '42';      $e->n;   // 42        (int)
$e->amt = '9.95';    $e->amt; // 9.95      (float)
$e->b   = '1';       $e->b;   // true      (bool)
$e->ib  = true;      $e->toRawArray()['ib']; // 1   (int in DB shape)
$e->csv = ['a','b']; $e->csv; // ['a','b'] (array on read)
$e->ja  = '{"k":1}'; $e->ja;  // ['k' => 1]
$e->dt  = '2024-01-02 03:04:05';
$e->dt;              // DateTimeImmutable
```

### 3. Custom cast (value object)

```php
use Project\Support\Casting\CastInterface;

final class MoneyCast implements CastInterface
{
    public static function get(mixed $v, array $p = [], ?object $h = null): Money
    {
        return Money::ofCents((int) $v);      // DB int cents -> Money VO
    }
    public static function set(mixed $v, array $p = [], ?object $h = null): int
    {
        return $v instanceof Money ? $v->cents() : (int) $v;
    }
}

final class Order extends Entity
{
    protected array $customCasters = ['money' => MoneyCast::class];
    protected array $casts         = ['total' => 'money'];
}

$order = new Order();
$order->total = Money::of(19.99);          // stored as 1999 (cents)
$order->total;                             // Money VO again
$order->toRawArray()['total'];             // 1999
```

### 4. Accessors & mutators

```php
final class Person extends Entity
{
    protected array $casts = ['name' => 'string'];

    // read transform (runs AFTER cast)
    public function getNameAttribute($v): string { return ucwords((string) $v); }

    // write transform (runs BEFORE cast)
    public function setEmailAttribute($v): string { return strtolower(trim((string) $v)); }

    // computed, exposed via $appends
    protected array $appends = ['initials'];
    public function getInitialsAttribute(): string
    {
        return implode('', array_map(fn($p) => $p[0] ?? '', explode(' ', $this->getString('name'))));
    }
}

$p = new Person();
$p->name  = 'ada lovelace';   $p->name;  // 'Ada Lovelace'
$p->email = '  A@B.C ';        $p->getRawAttribute('email'); // 'a@b.c'
$p->toArray()['initials'];     // 'AL'
```

### 5. Mass assignment — safe vs. forced

```php
final class Account extends Entity
{
    protected array $fillable = ['name', 'email'];   // only these are mass-assignable
    // $guarded defaults to ['*'] => everything else blocked
}

$a = (new Account())->fill([
    'name'     => 'ada',
    'email'    => 'a@b.c',
    'is_admin' => true,      // ← silently dropped (not fillable)
    'id'       => 999,       // ← silently dropped
]);
$a->hasAttribute('is_admin'); // false

// trusted, internal data only:
$a->forceFill(['id' => 7, 'is_admin' => true]);
$a->isFillable('email');      // true
$a->isFillable('is_admin');   // false
```

Whitelist instead of default-deny:

```php
final class Tag extends Entity
{
    protected array $guarded = ['id'];   // everything fillable EXCEPT id
}
```

### 6. Typed, null-safe getters

```php
$e->getString('name', 'anon');   // string, default if null
$e->getInt('age');               // 0 if missing/non-numeric
$e->getFloat('rate');            // 0.0 default
$e->getBool('active');           // false default; understands "1"/"true"/"on"/"yes"
$e->getArray('roles');           // [] default; decodes a JSON string too
$e->getDate('createdAt');        // ?DateTimeImmutable (parses int/string)
```

### 7. Visibility — static and runtime

```php
final class Secretish extends Entity
{
    protected array $hidden = ['password'];
}

$s = Secretish::reconstitute(['id' => 1, 'password' => 'x', 'name' => 'ada']);
$s->toArray();                       // ['id'=>1, 'name'=>'ada']  (no password)

$s->makeVisible('password');         // expose at runtime
array_key_exists('password', $s->toArray()); // true

$s->makeHidden(['name']);            // hide more at runtime
$s->toArray();                       // ['id'=>1, 'password'=>'x']

// whitelist mode — ONLY listed fields ever appear:
final class Slim extends Entity { protected array $visible = ['id', 'name']; }
```

### 8. Serialization surfaces

```php
$e->toArray();                 // cast + visibility + dates + appends
$e->toArray(onlyChanged: true);// only changed fields
$e->toRawArray();              // raw DB-shaped attributes (for persistence)
$e->jsonSerialize();           // == toArray()
$e->toJson(JSON_PRETTY_PRINT); // string (throws on encode error)
(string) $e;                   // JSON via Stringable
json_encode($e);               // uses JsonSerializable automatically

// dates honour $dates + $dateFormat
final class Event extends Entity {
    protected array $dates = ['startsAt'];
    protected string $dateFormat = 'Y-m-d';
}
$ev = Event::reconstitute(['startsAt' => '2024-12-25 10:00:00']);
$ev->toArray()['startsAt'];    // '2024-12-25'
```

### 9. ArrayAccess

```php
$e['title'] = 'Hello';        // setAttribute (mutator + cast)
$e['title'];                  // getAttribute (cast + accessor)
isset($e['title']);           // accessor value !== null
unset($e['title']);           // removes from the bag
```

### 10. Change tracking & dirty-only persistence

```php
$e = Article::reconstitute(['id' => 1, 'title' => 'A', 'views' => 10]);
$e->isDirty();                 // false (just hydrated)

$e->title = 'B';
$e->views = 11;
$e->isDirty();                 // true
$e->isDirty('title');          // true
$e->isClean('id');             // true
$e->wasChanged('views');       // true
$e->getDirty();                // ['title'=>'B', 'views'=>11]
$e->getChanges();              // (alias of getDirty)
$e->getOriginal('title');      // 'A'
$e->getOriginal();             // full original snapshot

// persist only what changed, then re-baseline
$db->upsert('articles', $e->toRawArray(onlyChanged: true), ['id']);
$e->syncOriginal();
$e->isDirty();                 // false again
```

### 11. Domain events (Service pattern)

```php
final class Subscription extends Entity
{
    public static function start(string $plan): self
    {
        $s = (new self())->forceFill(['plan' => $plan, 'status' => 'active']);
        $s->recordEvent(new SubscriptionStarted($plan));
        return $s;
    }

    public function cancel(): void
    {
        if ($this->getString('status') === 'cancelled') {
            throw new \DomainException('Already cancelled');
        }
        $this->status = 'cancelled';
        $this->recordEvent(new SubscriptionCancelled($this->getKey()));
    }
}

// In the Application Service — flush inside the transaction:
$sub->cancel();
$this->collector->beginCollection();
$this->transaction->begin();
try {
    $this->repository->save($sub);
    foreach ($sub->releaseEvents() as $event) {   // returns AND clears
        $this->collector->collect($event);
    }
    $this->transaction->commit();
} catch (\Throwable $e) {
    $this->transaction->rollback();
    $this->collector->discard();
    throw $e;
}

$sub->hasEvents();   // false — buffer drained
```

### 12. Immutability sealing (read-only snapshots)

```php
$snapshot = Article::reconstitute($row)->seal();
$snapshot->title;             // ✅ reads fine
try {
    $snapshot->title = 'x';   // ❌ throws LogicException
} catch (\LogicException $e) { /* sealed */ }

$snapshot->isSealed();        // true
$editable = clone $snapshot;  // clone is UNSEALED + tracking reset
$editable->isSealed();        // false
```

### 13. Replication & cloning

```php
$tpl = Article::reconstitute(['id' => 5, 'title' => 'Template', 'views' => 99]);

$copy = $tpl->replicate();              // no primary key
$copy->getRawAttribute('id');           // null  → save() inserts a new row
$copy->getString('title');              // 'Template'

$copy2 = $tpl->replicate(except: ['views']);  // also drop views

$clone = clone $tpl;                    // keeps attributes; resets original/events/seal
$clone->getOriginal();                  // []
```

### 14. Identity & comparison

```php
$a = Article::reconstitute(['id' => 1]);
$b = Article::reconstitute(['id' => 1]);
$c = Article::reconstitute(['id' => 2]);
$new = new Article();

$a->is($b);          // true  (same class + same non-empty key)
$a->isNot($c);       // true
$a->is($new);        // false (new has no key)
$new->exists();      // false
$a->getKey();        // 1
$a->getKeyName();    // 'id'
```

### 15. `only()` / `except()`

```php
$e->only(['id', 'title']);         // ['id'=>.., 'title'=>..]  (cast values)
$e->except(['authorEmail']);       // toArray() minus those keys
```

### 16. Full Repository CRUD with the Hydrator

```php
use Project\Support\Hydration\DataConverter;

final class ArticleRepository
{
    private DataConverter $converter;

    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity $identity,
    ) {
        $this->converter = new DataConverter(
            types: ['id' => 'int', 'published' => 'bool', 'meta' => 'json[array]'],
            reconstructor: 'reconstitute',   // Article::reconstitute(array)
            extractor:     'toRawArray',     // Article::toRawArray()
        );
    }

    public function find(string $id): Article
    {
        $row = $this->db->queryOne(
            'SELECT * FROM articles WHERE id = :id AND tenant_id = :t',
            ['id' => $id, 't' => $this->identity->tenantId],
        ) ?? throw new RepositoryException("Article [{$id}] not found", layer: 'repository.article');

        return $this->converter->reconstruct(Article::class, $row);  // casts applied
    }

    /** @return Article[] */
    public function all(): array
    {
        $rows = $this->db->query('SELECT * FROM articles WHERE tenant_id = :t',
            ['t' => $this->identity->tenantId]);

        return array_map(fn($r) => $this->converter->reconstruct(Article::class, $r), $rows);
    }

    public function save(Article $a): void
    {
        $this->db->upsert('articles', $this->converter->extract($a), ['id']);
        $a->syncOriginal();
    }
}
```

### 17. Serializing a collection

```php
$articles = $repo->all();
$payload  = array_map(static fn(Article $a) => $a->toArray(), $articles);
$json     = json_encode($articles);   // each element uses JsonSerializable
```

### 18. Safe logging (secret redaction)

```php
final class Credentials extends Entity { protected array $hidden = ['secret', 'token']; }

$c = Credentials::reconstitute(['id' => 1, 'secret' => 'sk_live_x', 'token' => 'abc']);
var_dump($c);
// ['id'=>1, 'secret'=>'********', 'token'=>'********']  ← __debugInfo() redaction
log_debug(print_r($c, true));    // also redacted
```

---

## Design notes & caveats

- This is a **convenience** base with a public attribute bag. The strict GDA gold
  standard is still a `final` entity with a private constructor and fully
  encapsulated state (private typed properties, no bag). Extend this base when
  the flexible, WordPress-style attribute bag genuinely earns its keep
  (heterogeneous/meta-driven records); prefer a hand-written `final` entity for
  small, well-defined aggregates.
- `static::$methodCache` memoizes `method_exists` results. It caches **immutable
  facts** (does class X define method Y), not request data, so it is safe under
  OpenSwoole and does not leak between requests.
- `exists()` treats `null`, `''`, `0`, `'0'` as "no key".
- `offsetExists()`/`__isset()` use the **accessor** value (so a `null` cast result
  reads as not-set); use `hasAttribute()` for a pure key-presence check.
- The base never validates business rules — invariants belong in the entity's own
  transition methods (throwing `\DomainException`) and in DTOs.
