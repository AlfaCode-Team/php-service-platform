<?php

namespace Plugins\SiteSEO\Types\Twitter;

use Plugins\SiteSEO\TwitterType;

class Player extends TwitterType
{
    /** @var string */
    protected $type = 'player';

    public function player(string $url, int $width, int $height)
    {
        $this->setProperty(self::PREFIX, 'player', $url);
        $this->setProperty(self::PREFIX, 'player:width', $width);
        $this->setProperty(self::PREFIX, 'player:height', $height);

        return $this;
    }
}
