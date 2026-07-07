<?php
namespace Plugins\SiteSEO\Helpers;

/**
 * @package    Plugins\SiteSEO
 * @author     hakeem shamavu <hakimushamavu@gmail.com>
 * @copyright  Copyright (c) 2022 - present Hakeem Shamavu
 * @license    MIT License
 */
class Escape
{

	public static $encoding = 'UTF-8';

	public static function escape(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, static::$encoding);
	}

	/**
	 * Escape url for sitemaps.
	 *
	 * @param  string $url
	 * @return string
	 */
	public static function escapeUrl(string $url): string
	{
		$url = parse_url($url);
		$url['path'] = $url['path'] ?? '';
		$url['query'] = $url['query'] ?? '';

		if ($url['path'] !== '') {
			$url['path'] = implode('/', array_map('rawurlencode', explode('/', $url['path'])));
		}

		if ($url['query'] !== '') {
			$url['query'] = "?{$url['query']}";
		}

		return str_replace(
			['&', "'", '"', '>', '<'],
			['&amp;', '&apos;', '&quot;', '&gt;', '&lt;'],
			$url['scheme'] . "://{$url['host']}{$url['path']}{$url['query']}"
		);
	}
}