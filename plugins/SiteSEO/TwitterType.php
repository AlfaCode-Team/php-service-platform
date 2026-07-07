<?php

namespace Plugins\SiteSEO;

abstract class TwitterType extends BaseObject
{
    protected const PREFIX = 'twitter';

    public function __construct(string|null $title = null)
    {
        $this->setProperty(self::PREFIX, 'card', $this->type);
        $this->when($title)->title($title);
    }

    public static function make(string|null $title = null)
    {
        return new static($title);
    }

    public function title(string|null $title)
    {
        $this->setProperty(self::PREFIX, 'title', $title);

        return $this;
    }

    public function site(string $site)
    {
        $this->setProperty(self::PREFIX, 'site', $site);

        return $this;
    }

    public function url(string $url)
    {
        $this->setProperty(self::PREFIX, 'url', $url);

        return $this;
    }

    public function description(string $description)
    {
        $this->setProperty(self::PREFIX, 'description', $description);

        return $this;
    }

    public function image(string $image, null|string $alt = null)
    {
        $this->setProperty(self::PREFIX, 'image', $image);
        $this->when($alt)->setProperty(self::PREFIX, 'image:alt', $alt);

        return $this;
    }

    public function setProperty(string $prefix, string $property, string $content)
    {
        $this->tags[$prefix.':'.$property] = TwitterProperty::make($prefix, $property, $content);
    }

    public function addProperty(string $prefix, string $property, string $content)
    {
        $this->tags[] = TwitterProperty::make($prefix, $property, $content);
    }
    public function addStructuredProperty(BaseObject $property)
    {
        // Twitter cards only understand a single image URL plus optional alt
        // text — they have no width/height/secure_url/type tags. Map the OG
        // structured image onto the correct twitter:image / twitter:image:alt
        // tags instead of leaking og:image:* keys as bogus twitter:* tags (which
        // would also clobber twitter:url with the image URL).
        $props = $property->getProperties();

        $imageUrl = $props['secure_url'] ?? $props['url'] ?? null;
        if ($imageUrl !== null) {
            $this->setProperty(self::PREFIX, 'image', (string) $imageUrl);
        }

        if (isset($props['alt'])) {
            $this->setProperty(self::PREFIX, 'image:alt', (string) $props['alt']);
        }
    }
}
