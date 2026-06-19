<?php

namespace Plugins\SiteSEO;

/**
 * Class XRobotsTag
 *
 * A class to generate X-Robots-Tag header and meta tags for controlling search engine indexing and caching.
 */
class XRobotsTag
{
    const NOINDEX = 'noindex';
    const INDEX = 'index';
    const NOFOLLOW = 'nofollow';
    const FOLLOW = 'follow';
    const NOARCHIVE = 'noarchive';
    const ARCHIVE = 'archive';
    const NOSNIPPET = 'nosnippet';
    const SNIPPET = 'snippet';
    const MAX_SNIPPET = 'max-snippet';
    const MAX_VIDEO_PREVIEW = 'max-video-preview';
    const MAX_IMAGE_PREVIEW = 'max-image-preview';
    const TRANSLATE = 'translate';
    // googlebot
    // nopagereadaloud
    // noimageindex
    const NOTRANSLATE = 'notranslate';

    /** @var array Array to store directives */
    private $directives = [];


    public function is_public()
    {
        $this->addDirective(self::INDEX);
        $this->addDirective(self::FOLLOW);
        $this->addDirective(self::NOARCHIVE);
        $this->addDirective(self::TRANSLATE);
        $this->addDirective(self::MAX_SNIPPET, 150); // Setting max-snippet to 150
        $this->addDirective(self::MAX_IMAGE_PREVIEW, 'large'); // Setting max-image-preview to large

        return $this;
    }

    public function is_private()
    {
        $this->addDirective(self::NOINDEX);
        $this->addDirective(self::NOFOLLOW);
        $this->addDirective(self::NOARCHIVE);
        $this->addDirective(self::NOSNIPPET);
        return $this;
    }

    /**
     * Add a directive to the list.
     *
     * @param string $directive The directive to add.
     * @param mixed|null $value Optional value for the directive.
     */
    public function addDirective(string $directive, $value = true): void
    {
        $this->directives[$directive] = $value;
    }

    /**
     * Generate X-Robots-Tag header.
     *
     * @return string The generated X-Robots-Tag header.
     */
    public function generateHeader(): string
    {
        $directives = [];
        foreach ($this->directives as $directive => $value) {
            $directives[] = $value === true ? $directive : "$directive=$value";
        }
        return 'X-Robots-Tag: ' . implode(', ', $directives);
    }

    /**
     * Generate meta tags for robots directives.
     *
     * @return string The generated meta tags.
     */
    public function generateMetaTags(): string
    {
        $metaTags = '';
        $cont = "";
        foreach ($this->directives as $directive => $value) {
            $content = $value === true ? $directive : "$directive:$value";
            $cont .=", ".$content; 
        }
        $cont = trim($cont,", ");
        $metaTags .= "<meta name=\"robots\" content=\"$cont\">" . PHP_EOL;
        $metaTags .= "<meta name=\"googlebot\" content=\"$cont\">" . PHP_EOL;
        $metaTags .= "<meta name=\"bingbot\" content=\"$cont\">" . PHP_EOL;
        return $metaTags;
    }

    public function __toString()
    {
        // Set X-Robots-Tag header
        header($this->generateHeader());

        // Generate meta tags
        return $this->generateMetaTags();
    }
}
