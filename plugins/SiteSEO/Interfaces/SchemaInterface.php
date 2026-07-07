<?php
namespace Plugins\SiteSEO\Interfaces;

/**
 * @package    Plugins\SiteSEO
 * @author     hakeem shamavu <hakimushamavu@gmail.com>
 * @copyright  Copyright (c) 2022 - present Hakeem Shamavu
 * @license    MIT License
 */
interface SchemaInterface extends SeoInterface, \JsonSerializable
{
	public function __toString(): string;
}
