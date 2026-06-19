<?php

namespace Plugins\SiteSEO\Types\Twitter;

use Plugins\SiteSEO\TwitterType;

class SummaryLargeImage extends TwitterType
{
    /** @var string */
    protected $type = 'summary_large_image';

    public function creator(string $creator)
    {
        $this->setProperty(self::PREFIX, 'creator', $creator);

        return $this;
    }
}
