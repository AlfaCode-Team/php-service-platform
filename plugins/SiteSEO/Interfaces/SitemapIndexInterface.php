<?php
namespace Plugins\SiteSEO\Interfaces;

/**
 * @package Plugins\SiteSEO
 * @since v1.0
 * @license MIT
 * @copyright 2012-present Hkmcode.phpshots
 */
interface SitemapIndexInterface extends SitemapInterface
{
	public function __construct(string $domain, array $options = null);

	public function setOptions(array $options): SitemapIndexInterface;

	public function getOptions(): array;

	public function saveTo(string $path): bool;

	public function save(): bool;

	public function build(SitemapBuilderInterface $builder, array $options, callable $func): SitemapIndexInterface;

	public function __call(string $builder, array $args): SitemapIndexInterface;	
}
