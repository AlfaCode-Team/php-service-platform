# Casting & Hydration (`Project\Support\Casting` + `Project\Support\Hydration`)

Dependency-free, DI-free type-casting and row-hydration utilities, ported from
the legacy `__DEV__/DataCaster`, `__DEV__/DataConverter` and the `__DEV__/Entity`
Active-Record base class — refactored to obey the GDA layer rules.

- **Namespaces:** `Project\Support\Casting`, `Project\Support\Casting\Casts`,
  `Project\Support\Hydration`
- **Autoload:** `Project\` → `projects/` (PSR-4, already in `composer.json`)
- **No dependencies:** no Carbon, no `BaseConnection`, no WordPress helpers, no
  container, no globals — pure value transformation, safe to use from any layer.

---

## Table of contents

1. [Why the old `Entity` was decomposed](#why-the-old-entity-was-decomposed)
2. [What was dropped / changed in the port](#what-was-dropped--changed-in-the-port)
3. [Components at a glance](#components-at-a-glance)
4. [`DataCaster` — the engine](#datacaster--the-engine)
5. [Type-string grammar (`TypeParser`)](#type-string-grammar-typeparser)
6. [Built-in casts](#built-in-casts)
7. [Custom casts](#custom-casts)
8. [`DataConverter` — the hydrator](#dataconverter--the-hydrator)
9. [Cookbook — exhaustive examples](#cookbook--exhaustive-examples)
10. [Design notes & caveats](#design-notes--caveats)

---

## Why the old `Entity` was decomposed

`__DEV__/Entity/Entity.php` is a CodeIgniter/Eloquent-style **fat Active Record**:
magic `__get/__set`, mutators/accessors, `save()/delete()/restore()`,
`performInsert/Update`, `getRepo_()`, WP-style meta tables and change tracking —
all in one base class. GDA explicitly forbids this:

> ✗ Eloquent, Active Record, or any ORM in the Domain layer
> ✗ Domain importing anything external · ✗ an entity calling its own repository

So the single class is split across the layers it was conflating:

| Old `Entity` responsibility | GDA home |
| --- | --- |
| Attributes, state transitions, change tracking, invariants | **Domain entity** (`final`, private ctor, `create()`/`reconstitute()`, `releaseEvents()`) — or the [`Entity` base](../Entity/README.md) |
| `save()` / `delete()` / `performInsert/Update` / meta tables | **Repository** (`DatabasePort` only, tenant-scoped SQL) |
| Type casting on read/write, DB-row ⇄ object mapping | **this Support layer** (`DataCaster` + `DataConverter`) |
| Mass-assignment (`fillable`/`guarded`), validation | **DTO** (`fromRequest()` validation) at the controller edge |
| `toArray()` / `jsonSerialize()` | Response **DTO** `toArray()` |

The casting/mapping concern is the only genuinely reusable, rule-compliant piece,
so it lives here. The rest is per-domain code that belongs in each plugin.

## What was dropped / changed in the port

- **No Carbon / `BaseConnection`** — `DatetimeCast` and `TimestampCast` use
  `\DateTimeImmutable` (the framework's Domain date type).
- **No WP `maybe_serialize`** — `ArrayCast` uses native `serialize()` with
  `allowed_classes => false` on read.
- **Dropped legacy-VO casts** (`StatusCast`, `ProjectStatusCast`, `URICast`) —
  they coupled to `HKM\lib\Common\ValueObjects\*` which is not part of this
  framework. Re-add them per-domain as custom handlers (see below).
- **`DataConverter` no longer touches `Entity` or `kernel()`** — it reconstructs
  via an explicit static factory (`reconstitute` by default) or a closure, with
  no reflection back-door.

---

## Components at a glance

| Class | Role |
| --- | --- |
| `Casting\DataCaster` | Casts one field value, either direction (`get`/`set`) |
| `Casting\TypeParser` | Parses a type string into `{nullable, baseType, params}` |
| `Casting\CastInterface` | The contract every cast implements (`get`/`set`) |
| `Casting\BaseCast` | Identity cast; subclass and override one direction |
| `Casting\CastException` | Thrown on invalid handler / JSON |
| `Casting\Casts\*` | The 11 built-in casts |
| `Hydration\DataConverter` | Maps a whole DB row ⇄ object (uses `DataCaster`) |

---

## `DataCaster` — the engine

```php
use Project\Support\Casting\DataCaster;

new DataCaster(
    ?array  $castHandlers = null,  // custom [type => CastInterface::class], merged over defaults
    ?array  $types        = null,  // [field => typeString]
    ?object $helper       = null,  // passed as 3rd arg to every cast (e.g. a connection)
    bool    $strict       = true,  // true: passing null to a non-nullable type throws
);
```

| Method | Returns | Notes |
| --- | --- | --- |
| `setTypes(array $types)` | `static` | Replace the field→type map (clears the parse cache) |
| `castAs(mixed $value, string $field, 'get'\|'set' $method='get')` | `mixed` | Cast `$value` for `$field`; unknown field → returned unchanged |

Direction:

- `'get'` = **DataSource → PHP** (reading a DB row)
- `'set'` = **PHP → DataSource** (writing to the DB)

Nullability & strictness:

- Prefix a type with `?` to let `null` pass through untouched in either direction.
- `strict: true` (default): passing `null` to a **non**-nullable type throws
  `InvalidArgumentException`.
- A field with no entry in `$types` is returned verbatim (no-op).

```php
$caster = new DataCaster(types: [
    'id'      => 'int',
    'price'   => 'float',
    'active'  => 'bool',
    'tags'    => 'csv',
    'meta'    => '?json[array]',
    'created' => 'datetime',
], strict: false);

$caster->castAs('42', 'id');                 // 42                  (get)
$caster->castAs('a,b', 'tags');              // ['a', 'b']          (get)
$caster->castAs(null, 'meta');               // null                (nullable)
$caster->castAs($dateTime, 'created', 'set');// 'YYYY-mm-dd H:i:s'  (set)
$caster->castAs('whatever', 'unknown');      // 'whatever'          (no type → no-op)
```

---

## Type-string grammar (`TypeParser`)

```text
"?"? baseType ( "[" param ( "," param )* "]" )?
```

| Input | nullable | baseType | params |
| --- | --- | --- | --- |
| `int` | `false` | `int` | `[]` |
| `?string` | `true` | `string` | `[]` |
| `json[array]` | `false` | `json` | `['array']` |
| `?datetime[ms]` | `true` | `datetime` | `['ms']` |
| `datetime[Y-m-d]` | `false` | `datetime` | `['Y-m-d']` |

```php
use Project\Support\Casting\TypeParser;

TypeParser::parse('?json[array]');
// ['nullable' => true, 'baseType' => 'json', 'params' => ['array']]
```

You rarely call this directly — `DataCaster` uses it internally and caches the
result per field.

---

## Built-in casts

All live in `Project\Support\Casting\Casts`. "get" = DB→PHP, "set" = PHP→DB.
Casts with an identity "set" (BaseCast default) store the value unchanged.

| Type key(s) | Class | get (DB → PHP) | set (PHP → DB) |
| --- | --- | --- | --- |
| `int`, `integer` | `IntegerCast` | `int` | _identity_ |
| `float`, `double` | `FloatCast` | `float` | _identity_ |
| `string` | `StringCast` | `string` | _identity_ |
| `bool`, `boolean` | `BooleanCast` | `bool` (`filter_var`; `t`/`f` for PG) | _identity_ |
| `int-bool` | `IntBoolCast` | `bool` | `int` (0/1) — requires bool input |
| `csv` | `CSVCast` | `string` → `array` (split `,`) | `array` → `string` (join `,`) |
| `array` | `ArrayCast` | `string` → `array` (native unserialize) | `array` → `string` (`serialize`) |
| `json` | `JsonCast` | `string` → `stdClass` (or `array` w/ `[array]`) | value → JSON `string` |
| `object` | `ObjectCast` | `(object)` cast | _identity_ |
| `datetime` | `DatetimeCast` | `string` → `DateTimeImmutable` | `DateTimeInterface` → `string` |
| `timestamp` | `TimestampCast` | `int`/`string` → `DateTimeImmutable` | `DateTimeInterface` → `int` |

Notes:

- **`bool` vs `int-bool`** — `bool` only transforms on read; if your column
  stores `0/1` and you want the **write** to emit an int, use `int-bool`.
- **`json[array]`** decodes objects as associative arrays; plain `json` yields a
  `stdClass`.
- **`datetime` format param** — `''`→`Y-m-d H:i:s`, `ms`→`…H:i:s.v`,
  `us`→`…H:i:s.u`, or any literal PHP date format (e.g. `datetime[Y-m-d]`).

---

## Custom casts

Implement `CastInterface` (or extend `BaseCast` to inherit identity behaviour for
the direction you don't need) and register it by type key:

```php
use Project\Support\Casting\CastInterface;

final class MoneyCast implements CastInterface
{
    public static function get(mixed $value, array $params = [], ?object $helper = null): Money
    {
        return Money::ofCents((int) $value);          // DB int cents → Money VO
    }

    public static function set(mixed $value, array $params = [], ?object $helper = null): int
    {
        return $value instanceof Money ? $value->cents() : (int) $value;
    }
}

$caster = new DataCaster(
    castHandlers: ['money' => MoneyCast::class],
    types:        ['total' => 'money'],
);

$caster->castAs(1999, 'total');          // Money(19.99)
$caster->castAs(Money::of(19.99), 'total', 'set'); // 1999
```

Custom handlers are **merged over** the defaults, so you can also override a
built-in type key with your own implementation.

---

## `DataConverter` — the hydrator

Maps a whole row, both directions, running every field through a pooled
`DataCaster`. This is what a Repository uses instead of the old
`Entity::find()/save()`.

```php
use Project\Support\Hydration\DataConverter;

new DataConverter(
    array          $types,                          // [column => typeString]
    array          $castHandlers = [],              // custom casts
    ?object        $helper       = null,
    Closure|string $reconstructor = 'reconstitute', // static factory name OR closure
    Closure|string $extractor     = 'toRawArray',   // method name OR closure
);
```

| Method | Returns | Notes |
| --- | --- | --- |
| `fromDataSource(array $row)` | `array` | Row → PHP-typed array (`get` on each known field) |
| `toDataSource(array $php)` | `array` | PHP array → DB-typed array (`set` on each known field) |
| `reconstruct(string $class, array $row)` | `object` | Hydrate an object from a raw row |
| `extract(object $obj)` | `array` | Object → DB-typed column array |

Reconstruction resolves in this order:

1. a **`Closure`** reconstructor → `$closure($phpData)`
2. a **static factory** named by the string (default `reconstitute`) →
   `Class::reconstitute($phpData)`
3. otherwise throws `RuntimeException` (no reflection back-door).

Extraction resolves: a **`Closure`** → a **method name** (default `toRawArray`) →
fallback to public state via `(array) $object` (private/protected keys dropped).

---

## Cookbook — exhaustive examples

### 1. Standalone caster, both directions

```php
$c = new DataCaster(types: ['n' => 'int', 'on' => 'bool'], strict: false);
$c->castAs('7', 'n');          // 7
$c->castAs('true', 'on');      // true
$c->castAs(7, 'n', 'set');     // 7 (IntegerCast set is identity)
```

### 2. Every built-in type

```php
$c = new DataCaster(strict: false, types: [
    'i'  => 'int',     'f'  => 'float',  's'  => 'string', 'b'  => 'bool',
    'ib' => 'int-bool','csv'=> 'csv',    'arr'=> 'array',  'j'  => 'json',
    'ja' => 'json[array]', 'o' => 'object', 'dt' => 'datetime', 'ts' => 'timestamp',
]);

$c->castAs('5', 'i');                 // 5
$c->castAs('9.95', 'f');              // 9.95
$c->castAs(123, 's');                 // '123'
$c->castAs('1', 'b');                 // true
$c->castAs(true, 'ib', 'set');        // 1
$c->castAs('a,b,c', 'csv');           // ['a','b','c']
$c->castAs(['x' => 1], 'arr', 'set'); // 'a:1:{s:1:"x";i:1;}'  (serialized)
$c->castAs('{"k":1}', 'j');           // stdClass { k: 1 }
$c->castAs('{"k":1}', 'ja');          // ['k' => 1]
$c->castAs('2024-01-02 03:04:05','dt');// DateTimeImmutable
$c->castAs('1700000000', 'ts');       // DateTimeImmutable @1700000000
```

### 3. Nullable vs. strict

```php
$strict = new DataCaster(types: ['x' => 'int']);            // strict: true (default)
$strict->castAs(null, 'x');     // ❌ InvalidArgumentException (not nullable)

$nullable = new DataCaster(types: ['x' => '?int']);
$nullable->castAs(null, 'x');   // null  (passes through)

$lenient = new DataCaster(types: ['x' => 'int'], strict: false);
// strict:false stops the null guard from throwing, but the handler may still
// reject null — always prefer the explicit `?int` for nullable columns.
```

### 4. Datetime formats

```php
$c = new DataCaster(strict: false, types: [
    'a' => 'datetime',          // Y-m-d H:i:s
    'b' => 'datetime[ms]',      // Y-m-d H:i:s.v
    'c' => 'datetime[Y-m-d]',   // literal format
]);
$dt = new DateTimeImmutable('2024-12-25 10:30:00');
$c->castAs($dt, 'a', 'set');    // '2024-12-25 10:30:00'
$c->castAs($dt, 'c', 'set');    // '2024-12-25'
$c->castAs('2024-12-25', 'c');  // DateTimeImmutable (parsed with that format)
```

### 5. Custom value-object cast

```php
final class MoneyCast implements \Project\Support\Casting\CastInterface {
    public static function get(mixed $v, array $p = [], ?object $h = null): Money { return Money::ofCents((int) $v); }
    public static function set(mixed $v, array $p = [], ?object $h = null): int    { return $v instanceof Money ? $v->cents() : (int) $v; }
}
$c = new DataCaster(castHandlers: ['money' => MoneyCast::class], types: ['total' => 'money']);
$c->castAs(2500, 'total');                 // Money(25.00)
$c->castAs(Money::of(25), 'total', 'set'); // 2500
```

### 6. Passing a helper to casts

```php
// The 3rd ctor arg is forwarded to every cast as $helper — e.g. a connection,
// a clock, or any context object a custom cast needs.
$c = new DataCaster(
    castHandlers: ['tzdate' => TimezoneDateCast::class],
    types:        ['at' => 'tzdate'],
    helper:       $clock,        // TimezoneDateCast::get($v, $p, $clock)
);
```

### 7. Reusing a caster with `setTypes`

```php
$c = new DataCaster(strict: false);
$c->setTypes(['a' => 'int'])->castAs('1', 'a');   // 1
$c->setTypes(['a' => 'bool'])->castAs('1', 'a');  // true  (parse cache reset)
```

### 8. Hydrating a single row

```php
use Project\Support\Hydration\DataConverter;

$conv = new DataConverter(
    types: ['id' => 'int', 'paid' => 'bool', 'meta' => 'json[array]'],
);
$invoice = $conv->reconstruct(Invoice::class, [
    'id' => '7', 'paid' => '1', 'meta' => '{"k":1}',
]);
// Invoice::reconstitute(['id'=>7, 'paid'=>true, 'meta'=>['k'=>1]])
```

### 9. Hydrating with a closure (no static factory)

```php
$conv = new DataConverter(
    types: ['id' => 'int'],
    reconstructor: fn(array $d) => new Dto($d['id']),
    extractor:     fn(Dto $o)   => ['id' => $o->id],
);
$dto = $conv->reconstruct(Dto::class, ['id' => '3']);  // Dto(3)
$row = $conv->extract($dto);                           // ['id' => 3]
```

### 10. Extracting an object to a DB row

```php
$conv = new DataConverter(
    types: ['id' => 'int', 'paid' => 'bool', 'meta' => 'json[array]'],
    extractor: 'toRawArray',
);
$columns = $conv->extract($invoice);   // ['id'=>3, 'paid'=>false, 'meta'=>'{"a":2}']
$db->upsert('invoices', $columns, ['id']);
```

### 11. Mapping arrays directly (no objects)

```php
$conv  = new DataConverter(types: ['id' => 'int', 'active' => 'bool']);
$php   = $conv->fromDataSource(['id' => '9', 'active' => '0', 'name' => 'ada']);
// ['id' => 9, 'active' => false, 'name' => 'ada']  (untyped keys pass through)
$store = $conv->toDataSource(['id' => 9, 'active' => false]);
// ['id' => 9, 'active' => false]
```

### 12. Full Repository CRUD (the GDA replacement for `Entity::find/save`)

```php
use Project\Support\Hydration\DataConverter;

final class InvoiceRepository
{
    private DataConverter $converter;

    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity $identity,
    ) {
        $this->converter = new DataConverter(
            types: ['id' => 'int', 'paid' => 'bool', 'meta' => 'json[array]'],
            reconstructor: 'reconstitute',   // public static Invoice::reconstitute(array): self
            extractor:     'toRawArray',     // public Invoice::toRawArray(): array
        );
    }

    public function find(string $id): Invoice
    {
        $row = $this->db->queryOne(
            'SELECT * FROM invoices WHERE id = :id AND tenant_id = :t',
            ['id' => $id, 't' => $this->identity->tenantId],
        ) ?? throw new RepositoryException("Invoice [{$id}] not found", layer: 'repository.invoice');

        return $this->converter->reconstruct(Invoice::class, $row);
    }

    /** @return Invoice[] */
    public function all(): array
    {
        $rows = $this->db->query(
            'SELECT * FROM invoices WHERE tenant_id = :t',
            ['t' => $this->identity->tenantId],
        );

        return array_map(fn($r) => $this->converter->reconstruct(Invoice::class, $r), $rows);
    }

    public function save(Invoice $invoice): void
    {
        $this->db->upsert('invoices', $this->converter->extract($invoice), ['id']);
    }
}
```

The Domain `Invoice` stays pure: a `final` class (or one extending the
[`Entity` base](../Entity/README.md)) with `static reconstitute(array)`,
`toRawArray()`, state-transition methods that record domain events, and zero
infrastructure imports.

### 13. Overriding a built-in type

```php
// Replace the default 'json' behaviour project-wide:
$conv = new DataConverter(
    types: ['payload' => 'json'],
    castHandlers: ['json' => StrictJsonCast::class],  // your own implementation wins
);
```

---

## Design notes & caveats

- **Casts are static & stateless** — pure transforms, safe to share and call
  concurrently (OpenSwoole-safe; no per-request state).
- **`DataConverter` pools `DataCaster` instances** keyed by a hash of
  `types + castHandlers`, so many converters with the same shape share one
  caster (memory win). The pool holds immutable config, not request data.
- **Prefer `?type` over `strict: false`** for nullable columns — it is explicit
  and survives a handler that rejects `null`.
- **`array` cast uses PHP `serialize()`** (unserialize is restricted to
  `allowed_classes => false`). Use `json`/`json[array]` if you need portable,
  language-agnostic storage.
- **No reflection back-door** — `reconstruct()` requires a static factory or a
  closure; it will not write private properties behind the entity's back. Give
  your Domain entity a `reconstitute()`/`toRawArray()` (the
  [`Entity` base](../Entity/README.md) provides both).
- This layer never does I/O. The DB call belongs to the Repository; the cast
  layer only shapes values.
