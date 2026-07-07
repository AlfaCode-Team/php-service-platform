<?php

namespace Plugins\SiteSEO\Schema\Things;

use Plugins\SiteSEO\Schema\Thing;

class ContactPoint extends Thing
{
	public function __construct()
	{
		parent::__construct("ContactPoint", []);
	}

	public function setTelephone(string $value) :self
	{
		$this->data['telephone']=$value;
		return $this;
	}

	public function setContactType(string $value) :self
	{
		$this->data['contactType']=$value;
		return $this;
	}
}