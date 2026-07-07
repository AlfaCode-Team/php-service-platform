<?php

namespace Plugins\SiteSEO;

use Plugins\SiteSEO\Twitter;
use Plugins\SiteSEO\Types\Twitter\Summary;
use Plugins\SiteSEO\StructuredProperties\Audio;
use Plugins\SiteSEO\StructuredProperties\Image;
use Plugins\SiteSEO\StructuredProperties\Video;

abstract class Type extends BaseObject
{
    /** @var string */
    protected $type;
    /**
     * @var Summary
     */
    protected $twitterMeta;

    protected $prs = [
        'title' => 'Hkmcode Website',
        'description' => ''
    ];

    public function __construct(string|null $title = null)
    {
        $this->setProperty('og', 'type', $this->type);
        $this->when($title)->title($title);
        $this->prs['title'] = $title;
        $twitterMeta = new Twitter();
        $this->twitterMeta = $twitterMeta->summary($title);
    }

    public static function make(string|null $title = null)
    {
        return new static($title);
    }

    public function title(string $title)
    {
        $this->setProperty('og', 'title', $title);

        return $this;
    }

    public function url(string $url)
    {
        $this->setProperty('og', 'url', $url);
        $this->twitterMeta->url($url);

        return $this;
    }

    public function description(string $description)
    {
        $this->setProperty('og', 'description', $description);
        $this->twitterMeta->description($description);
        $this->prs['description'] = $description;

        return $this;
    }

    public function determiner(string $determiner)
    {
        $this->setProperty('og', 'determiner', $determiner);

        return $this;
    }

    public function locale(string $locale)
    {
        $this->setProperty('og', 'locale', $locale);

        return $this;
    }

    public function siteName(string $locale)
    {
        $this->setProperty('og', 'site_name', $locale);

        return $this;
    }

    public function alternateLocale(string $locale)
    {
        $this->addProperty('og', 'locale:alternate', $locale);

        return $this;
    }

    /**
     * Switch the Twitter card to "summary_large_image" (big hero image) instead
     * of the default "summary" (small thumbnail). The right default for articles,
     * products and any page with a wide cover image.
     *
     * @return $this
     */
    public function twitterLargeImage()
    {
        $this->twitterMeta->setProperty('twitter', 'card', 'summary_large_image');

        return $this;
    }

    /**
     * @param  Image|string  $image
     * @return $this
     */
    public function image($image)
    {
        if ($image instanceof Image) {
            $this->addStructuredProperty($image);
            $this->twitterMeta->addStructuredProperty($image);

            return $this;
        }

        $this->addProperty('og', 'image', $image);
        $this->twitterMeta->image($image);

        return $this;
    }

    /**
     * @param  Video|string  $video
     * @return $this
     */
    public function video($video)
    {
        if ($video instanceof Video) {
            $this->addStructuredProperty($video);

            return $this;
        }

        $this->addProperty('og', 'video', $video);

        return $this;
    }

    /**
     * @param  Audio|string  $audio
     * @return $this
     */
    public function audio($audio)
    {
        if ($audio instanceof Audio) {
            $this->addStructuredProperty($audio);

            return $this;
        }

        $this->addProperty('og', 'audio', $audio);

        return $this;
    }

    public function addStructuredProperty(BaseObject $property)
    {
        $this->tags[] = $property;
    }

    public function __toString(): string
    {

        $contents = parent::__toString();

        $conTwitter = (string) $this->twitterMeta; 
        $conts = <<<END
        <!-- Primary Meta Tags -->
        <title>{$this->prs['title']}</title>
        <meta name="title" content="{$this->prs['title']}" />
        <meta name="description" content="{$this->prs['description']}" />

        <!-- Open Graph / Facebook -->
        $contents

        <!-- Twitter -->
        $conTwitter
        END;
        return $conts;
    }

}
