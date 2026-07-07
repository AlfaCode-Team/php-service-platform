<?php

namespace Plugins\SiteSEO;

use Plugins\SiteSEO\Types\Book;
use Plugins\SiteSEO\XRobotsTag;
use Plugins\SiteSEO\Types\Article;
use Plugins\SiteSEO\Types\Profile;
use Plugins\SiteSEO\Types\Website;
use Plugins\SiteSEO\Types\Music\Song;
use Plugins\SiteSEO\Types\Music\Album;
use Plugins\SiteSEO\Types\Video\Movie;
use Plugins\SiteSEO\Types\Video\Other;
use Plugins\SiteSEO\Types\Video\TvShow;
use Plugins\SiteSEO\Types\Video\Episode;
use Plugins\SiteSEO\Types\Music\Playlist;
use Plugins\SiteSEO\Types\Music\RadioStation;

class OpenGraph
{
    public static function website(string|null $title = null): Website
    {
        return Website::make($title);
    }


    public static function robot(bool $is_public = true): XRobotsTag
    {
        $rb = new XRobotsTag();
        if($is_public){
          return  $rb->is_public();
        }else{
            return  $rb->is_private();
        }
    }


    public static function article(string|null $title = null): Article
    {
        return Article::make($title);
    }

    public static function book(string|null $title = null): Book
    {
        return Book::make($title);
    }

    public static function profile(string|null $title = null): Profile
    {
        return Profile::make($title);
    }

    public static function movie(string|null $title = null): Movie
    {
        return Movie::make($title);
    }

    public static function tvShow(string|null $title = null): TvShow
    {
        return TvShow::make($title);
    }

    public static function episode(string|null $title = null): Episode
    {
        return Episode::make($title);
    }

    public static function other(string|null $title = null): Other
    {
        return Other::make($title);
    }

    public static function album(string|null $title = null): Album
    {
        return Album::make($title);
    }

    public static function song(string|null $title = null): Song
    {
        return Song::make($title);
    }

    public static function playlist(string|null $title = null): Playlist
    {
        return Playlist::make($title);
    }

    public static function radioStation(string|null $title = null): RadioStation
    {
        return RadioStation::make($title);
    }
}
