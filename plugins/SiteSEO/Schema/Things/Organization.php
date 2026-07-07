<?php

namespace Plugins\SiteSEO\Schema\Things;

use Plugins\SiteSEO\Schema\Thing;

class Organization extends Thing
{
	public function __construct()
	{
		parent::__construct("Organization", []);
	}

	public function setUrl(string $value) :self
	{
		$this->data['url']=$value;
		return $this;
	}

	public function setLogo(string $value) :self
	{
		$this->data['logo']=$value;
		return $this;
	}

	public function setContactPoint(ContactPoint $value) :self
	{
		$this->data['contactPoint']=$value;
		return $this;
	}
}