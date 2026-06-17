<?php

declare(strict_types=1);

namespace Plugins\Authorization\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use Plugins\Authorization\Engine\Model\Model;
use Plugins\Authorization\Engine\Persist\AdapterHelper;
use Plugins\Authorization\Engine\Interfaces\Persist\Adapter;
use Plugins\Authorization\Engine\Interfaces\Persist\BatchAdapter;
use Plugins\Authorization\Engine\Interfaces\Persist\FilteredAdapter;
use Plugins\Authorization\Engine\Persist\Adapters\Filter;

/**
 * Casbin policy adapter backed by the kernel DatabasePort.
 *
 * Replaces the framework-agnostic FileAdapter so policies live in the
 * `casbin_rule` table and respect the GDA "Repository → DatabasePort only" rule.
 *
 * Table shape (create via a LetMigrate migration):
 *   casbin_rule(id, ptype, v0, v1, v2, v3, v4, v5)
 */
final class DatabasePolicyAdapter implements Adapter, BatchAdapter, FilteredAdapter
{
    use AdapterHelper;

    private bool $filtered = false;

    public function __construct(
        private readonly DatabasePort $db,
        private readonly string $table = 'casbin_rule',
    ) {
    }

    public function loadPolicy(Model $model): void
    {
        try {
            $rows = $this->db->query("SELECT ptype, v0, v1, v2, v3, v4, v5 FROM {$this->table}");
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to load Casbin policy', layer: 'repository.authorization', previous: $e);
        }

        foreach ($rows as $row) {
            $this->loadPolicyArray($this->filterRule($row), $model);
        }
    }

    public function loadFilteredPolicy(Model $model, $filter): void
    {
        if ($filter === null) {
            $this->loadPolicy($model);
            return;
        }
        if (!$filter instanceof Filter) {
            throw new \InvalidArgumentException('Invalid filter type for DatabasePolicyAdapter.');
        }

        try {
            $rows = $this->db->query("SELECT ptype, v0, v1, v2, v3, v4, v5 FROM {$this->table}");
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to load filtered Casbin policy', layer: 'repository.authorization', previous: $e);
        }

        foreach ($rows as $row) {
            $rule = $this->filterRule($row);
            if ($this->matchesFilter($rule, $filter)) {
                $this->loadPolicyArray($rule, $model);
            }
        }

        $this->filtered = true;
    }

    public function isFiltered(): bool
    {
        return $this->filtered;
    }

    public function savePolicy(Model $model): void
    {
        try {
            $this->db->execute("DELETE FROM {$this->table}");

            foreach (($model['p'] ?? []) as $ptype => $ast) {
                foreach ($ast->policy as $rule) {
                    $this->insertRow($ptype, $rule);
                }
            }
            foreach (($model['g'] ?? []) as $ptype => $ast) {
                foreach ($ast->policy as $rule) {
                    $this->insertRow($ptype, $rule);
                }
            }
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to save Casbin policy', layer: 'repository.authorization', previous: $e);
        }
    }

    public function addPolicy(string $sec, string $ptype, array $rule): void
    {
        $this->insertRow($ptype, $rule);
    }

    public function addPolicies(string $sec, string $ptype, array $rules): void
    {
        foreach ($rules as $rule) {
            $this->insertRow($ptype, $rule);
        }
    }

    public function removePolicy(string $sec, string $ptype, array $rule): void
    {
        $where = ['ptype' => $ptype];
        foreach (array_values($rule) as $i => $value) {
            $where["v{$i}"] = $value;
        }
        $this->deleteWhere($where);
    }

    public function removePolicies(string $sec, string $ptype, array $rules): void
    {
        foreach ($rules as $rule) {
            $this->removePolicy($sec, $ptype, $rule);
        }
    }

    public function removeFilteredPolicy(string $sec, string $ptype, int $fieldIndex, string ...$fieldValues): void
    {
        $where = ['ptype' => $ptype];
        foreach ($fieldValues as $i => $value) {
            if ($value !== '') {
                $where['v' . ($fieldIndex + $i)] = $value;
            }
        }
        $this->deleteWhere($where);
    }

    /** @param array<int,string> $rule */
    private function insertRow(string $ptype, array $rule): void
    {
        $cols = ['ptype'];
        $params = ['ptype' => $ptype];
        foreach (array_values($rule) as $i => $value) {
            $cols[] = "v{$i}";
            $params["v{$i}"] = $value;
        }
        $placeholders = array_map(static fn(string $c) => ":{$c}", $cols);
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $this->db->execute($sql, $params);
    }

    /** @param array<string,string> $where */
    private function deleteWhere(array $where): void
    {
        $clauses = [];
        foreach (array_keys($where) as $col) {
            $clauses[] = "{$col} = :{$col}";
        }
        $sql = "DELETE FROM {$this->table} WHERE " . implode(' AND ', $clauses);
        $this->db->execute($sql, $where);
    }

    /**
     * Reduce a DB row to a trimmed rule array (drops null/empty trailing columns).
     *
     * @param array<string,mixed> $row
     * @return array<int,string>
     */
    private function filterRule(array $row): array
    {
        $rule = [$row['ptype']];
        for ($i = 0; $i <= 5; $i++) {
            $val = $row["v{$i}"] ?? null;
            if ($val === null || $val === '') {
                break;
            }
            $rule[] = $val;
        }
        return $rule;
    }

    /**
     * @param array<int,string> $rule full rule including ptype at index 0
     */
    private function matchesFilter(array $rule, Filter $filter): bool
    {
        $ptype = $rule[0];
        $values = array_slice($rule, 1);
        $criteria = $ptype === 'p' ? $filter->p : ($ptype === 'g' ? $filter->g : []);

        foreach ($criteria as $i => $expected) {
            if ($expected !== '' && ($values[$i] ?? null) !== $expected) {
                return false;
            }
        }
        return true;
    }
}
