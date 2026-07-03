<?php

declare(strict_types=1);

namespace Project\Support\Casting;

/**
 * Parses a cast type definition string into its components.
 *
 * Grammar:
 *   "?"? baseType ( "[" param ( "," param )* "]" )?
 *
 * Examples:
 *   "int"            => nullable:false baseType:"int"      params:[]
 *   "?string"        => nullable:true  baseType:"string"   params:[]
 *   "json[array]"    => nullable:false baseType:"json"     params:["array"]
 *   "?datetime[ms]"  => nullable:true  baseType:"datetime" params:["ms"]
 *
 * Replaces the legacy HKM\lib\Support\TypeParser dependency.
 */
final class TypeParser
{
    /**
     * @return array{nullable: bool, baseType: string, params: list<string>}
     */
    public static function parse(string $type): array
    {
        $type = trim($type);

        $nullable = false;
        if ($type !== '' && $type[0] === '?') {
            $nullable = true;
            $type = substr($type, 1);
        }

        $params = [];
        $baseType = $type;

        $open = strpos($type, '[');
        if ($open !== false && str_ends_with($type, ']')) {
            $baseType = substr($type, 0, $open);
            $inner = substr($type, $open + 1, -1);
            if ($inner !== '') {
                $params = array_map('trim', explode(',', $inner));
            }
        }

        return [
            'nullable' => $nullable,
            'baseType' => trim($baseType),
            'params'   => $params,
        ];
    }
}
