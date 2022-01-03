<?php

namespace Venalio\Redis;

use Venalio\Redis\Tracy\RedisPanel;
use Predis\Client;

class RedisClient extends Client
{
	/**
	 * @var string|null
	 */
	private $name;

	/**
	 * @var mixed|null
	 */
	private $uri;

	/**
	 * @var mixed|null
	 */
	private $configOptions;

	/**
	 * @var RedisPanel
	 */
	private $panel;

	/**
	 * RedisClient constructor.
	 *
	 * @param string $name
	 * @param mixed $uri
	 * @param mixed $options
	 */
	public function __construct($name = NULL, $uri = NULL, $options = NULL)
	{
		$this->name = $name;
		$this->uri = $uri;
		$this->configOptions = $options;

		parent::__construct($uri, $options);
	}

	/**
	 * @return string|null
	 */
	public function getPrefix()
	{
		return $this->configOptions['prefix'] ?? NULL;
	}

	/**
	 * @return string|array|null
	 */
	public function getUri()
	{
		return $this->uri;
	}

	/**
	 * @return string|null
	 */
	public function getOptions()
	{
		return $this->configOptions;
	}

	/**
	 * @param RedisPanel $panel
	 */
	public function setPanel($panel)
	{
		$this->panel = $panel;
		$this->panel->addConnection([
			'name'    => $this->name,
			'client'  => $this,
			'uri'     => $this->uri,
			'options' => $this->configOptions,
		]);
	}

	public function __call($commandID, $arguments)
	{
		if ($this->panel) {
			$this->panel->commandStart($commandID, $arguments);
		}

		$value = parent::__call($commandID, $arguments);

		if ($this->panel) {
			$this->panel->commandEnd($this->name, $commandID, $arguments, $value);
		}

		return $value;
	}
}