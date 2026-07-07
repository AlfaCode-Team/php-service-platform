<?php

namespace Plugins\SiteSEO;

use Plugins\SiteSEO\Types\Twitter\Player;
use Plugins\SiteSEO\Types\Twitter\Summary;
use Plugins\SiteSEO\Types\Twitter\SummaryLargeImage;

class Twitter
{
    public static function summary(string|null $title = null): Summary
    {
        return Summary::make($title);
    }

    public static function summaryLargeImage(string|null $title = null): SummaryLargeImage
    {
        return SummaryLargeImage::make($title);
    }

    public static function player(string|null $title = null): Player
    {
        return Player::make($title);
    }
}
