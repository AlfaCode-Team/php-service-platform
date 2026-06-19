<?php

namespace Plugins\SiteSEO\Types\Music;

use Plugins\SiteSEO\Type;

class RadioStation extends Type
{
    protected const PREFIX = 'music';

    /** @var string */
    protected $type = 'music.radio_station';

    public function creator(string $url)
    {
        $this->setProperty(self::PREFIX, 'creator', $url);

        return $this;
    }
}
