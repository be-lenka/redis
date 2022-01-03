<?php

namespace Venalio\Redis\Caching\Storage;

use Nette\Caching\Cache;
use Nette\Caching\IStorage;
use Nette\Utils\Strings;
use Predis\Collection\Iterator\Keyspace;
use Venalio\Redis\RedisClient;

class RedisStorage implements IStorage
{

	/**
	 * @var RedisClient
	 */
	private $client;

	public function __construct(RedisClient $client)
	{
		$this->client = $client;
	}

	public function read($key)
	{
		$data = $this->client->get($key);

		if ($data) {
			try {
				return self::unserialize($data);
			} catch (\Throwable $e) {
				return NULL;
			}
		}

		return NULL;
	}

	public function write($key, $data, array $dependencies)
	{
		$tags = '';
		if (isset($dependencies[Cache::TAGS])) {
			$tags = '#' . implode('#', array_values($dependencies[Cache::TAGS]));
			$key .= $tags;
		}

		if (isset($dependencies[Cache::EXPIRATION])) {
			$expiration = (int) $dependencies[Cache::EXPIRATION];

			if (isset($dependencies[Cache::SLIDING]) && $dependencies[Cache::SLIDING] !== TRUE) {
				$this->client->set($key, self::serialize($data));
				$this->client->expireat($key, time() + $expiration);
			} else {
				$this->client->setex($key, $expiration, self::serialize($data));
			}
		} else {
			$this->client->set($key, self::serialize($data));
		}
	}

	public function remove($key)
	{
		if (!is_array($key)) {
			$key = [$key];
		}

		$this->client->del($key);
	}

	public function clean(array $conditions)
	{
		$itemsPerPage = 100;
		$prefix = $this->client->getPrefix();
		if (isset($conditions[Cache::ITEMS]) && is_int($conditions[Cache::ITEMS]) && $conditions[Cache::ITEMS] > 0) {
			$itemsPerPage = $conditions[Cache::ITEMS];
		}

		if (isset($conditions[Cache::TAGS])) {
			$keysToRemove = [];
			$count = 0;
			foreach ($conditions[Cache::TAGS] as $tag) {
				$tagKey = '#' . $tag;
				foreach (new Keyspace($this->client, '*' . $tagKey . '*', $itemsPerPage) as $key) {
					$keysToRemove[] = $prefix ? substr($key, strlen($prefix)) : $key;
					$count++;

					if ($count == $itemsPerPage) {
						$this->remove($keysToRemove);
						$keysToRemove = [];
						$count = 0;
					}
				}
			}
		}
	}

	public function lock($key)
	{
		// Not implemented
	}

	private static function serialize($data)
	{
		return serialize($data);
	}

	private static function unserialize($data)
	{
		return unserialize($data);
	}

}