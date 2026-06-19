<?php

namespace Plugins\SiteSEO;

use Plugins\SiteSEO\Support\HasConditionalCalls;




abstract class BaseObject
{
    use HasConditionalCalls;

    /** @var BaseObject[] */
    protected $tags = [];
    protected $props = [];

    public function setProperty(string $prefix, string $property, string $content)
    {
        $this->props[$property] = $content;
        $this->tags[$prefix.':'.$property] = Property::make($prefix, $property, $content);
    }

    public function addProperty(string $prefix, string $property, string $content)
    {
        $this->tags[] = Property::make($prefix, $property, $content);
    }

    public function getProperties(): array{
        return $this->props;
    }

    public function __toString(): string
    {
        return implode(PHP_EOL, array_map('strval', $this->tags));
    }
}
