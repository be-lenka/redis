<?php declare(strict_types=1);

namespace Venalio\Redis\DI;

use Contributte\Redis\Caching\RedisStorage;
use Contributte\Redis\Exception\Logic\InvalidStateException;
use Nette\Caching\IStorage;
use Nette\DI\CompilerExtension;
use Nette\Http\Session;
use Nette\PhpGenerator\ClassType;
use Nette\Utils\Validators;
use RuntimeException;
use Venalio\Redis\RedisClient;
use Venalio\Redis\Session\Handlers\CustomSessionHandler;
use Venalio\Redis\Tracy\RedisPanel;

final class RedisExtension extends CompilerExtension
{

	/** @var mixed[] */
	private $defaults = [
		'debug'      => FALSE,
		'connection' => [],
	];

	/** @var mixed[] */
	private $connectionDefaults = [
		'uri'      => 'tcp://127.0.0.1:6379',
		'options'  => [],
		'storage'  => FALSE,
		'sessions' => FALSE,
	];

	/** @var mixed[] */
	private $sessionDefaults = [
		'ttl' => NULL,
	];

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->validateConfig($this->defaults);

		if (!isset($config['connection']['default'])) {
			throw new InvalidStateException(sprintf('%s.connection.default is required.', $this->name));
		}

		$connections = [];

		foreach ($config['connection'] as $name => $connection) {
			$isCluster = FALSE;
			$connection = $this->validateConfig($this->connectionDefaults, $connection, $this->prefix('connection.' . $name));

			$uri = $connection['uri'];
			if (is_array($uri)) {
				$isCluster = TRUE;
				if (count($uri) == 1) {
					$uri = $uri[0];
					$isCluster = FALSE;
				}
			}

			if ($isCluster && !isset($connection['options']['cluster'])) {
				$connection['options']['cluster'] = 'redis';
			}

			if (isset($connection['options']['prefix']) && substr($connection['options']['prefix'], -1) !== ':') {
				$connection['options']['prefix'] .= ':';
			}

			$client = $builder->addDefinition($this->prefix('connection.' . $name . '.client'))
				->setType(RedisClient::class)
				->setArguments([$name, $uri, $connection['options']]);

			if ($name !== 'default') {
				$client->setAutowired(FALSE);
			}

			$connections[] = [
				'name'    => $name,
				'client'  => $client,
				'uri'     => $uri,
				'options' => $connection['options'],
			];
		}

		if ($config['debug'] === TRUE) {
			$panelServiceName = $this->prefix('panel');
			$builder->addDefinition($panelServiceName)
				->setFactory(RedisPanel::class, []);

			foreach ($connections as $conn) {
				/** @var \Nette\DI\ServiceDefinition $client */
				$client = $conn['client'];
				$client->addSetup('setPanel', ['@' . $panelServiceName]);
			}
		}
	}

	public function beforeCompile(): void
	{
		$this->beforeCompileStorage();
		$this->beforeCompileSession();
	}

	public function beforeCompileStorage(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->validateConfig($this->defaults);

		foreach ($config['connection'] as $name => $connection) {
			$connection = $this->validateConfig($this->connectionDefaults, $connection, $this->prefix('connection.' . $name));

			// Skip if replacing storage is disabled
			if ($connection['storage'] === FALSE) continue;

			// Validate needed services
			if ($builder->getByType(IStorage::class) === NULL) {
				throw new RuntimeException(sprintf('Please install nette/caching package. %s is required', IStorage::class));
			}

			$builder->getDefinitionByType(IStorage::class)
				->setAutowired(FALSE);

			$builder->addDefinition($this->prefix('connection.' . $name . 'storage'))
				->setFactory(RedisStorage::class)
				->setAutowired(TRUE);
		}
	}

	public function beforeCompileSession(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->validateConfig($this->defaults);

		$sessionHandlingConnection = NULL;

		foreach ($config['connection'] as $name => $connection) {
			$connection = $this->validateConfig($this->connectionDefaults, $connection, $this->prefix('connection.' . $name));

			// Skip if replacing session is disabled
			if ($connection['sessions'] === FALSE) continue;

			if ($sessionHandlingConnection === NULL) {
				$sessionHandlingConnection = $name;
			} else {
				throw new InvalidStateException(sprintf(
					'Connections "%s" and "%s" both try to register session handler. Only one of them could have session handler enabled.',
					$sessionHandlingConnection,
					$name
				));
			}

			// Validate given config
			Validators::assert($connection['sessions'], 'bool|array');

			// Validate needed services
			if ($builder->getByType(Session::class) === NULL) {
				throw new RuntimeException(sprintf('Please install nette/http package. %s is required', Session::class));
			}

			// Validate session config
			if ($connection['sessions'] === TRUE) {
				$sessionConfig = $this->sessionDefaults;
			} else {
				$sessionConfig = $this->validateConfig($this->sessionDefaults, $connection['sessions'], $this->prefix('connection.' . $name . 'sessions'));
			}

			$sessionHandler = $builder->addDefinition($this->prefix('sessionHandler'))
				->setType(CustomSessionHandler::class)
				->setArguments([$this->prefix('@connection.' . $name . '.client'), ['gc_maxlifetime' => $sessionConfig['ttl']]]);

			$builder->getDefinitionByType(Session::class)
				->addSetup('setHandler', [$sessionHandler]);
		}
	}

	public function afterCompile(ClassType $class): void
	{
		$config = $this->validateConfig($this->defaults);

		if ($config['debug'] === TRUE) {
			$initialize = $class->getMethod('initialize');
			$initialize->addBody('$this->getService(?)->addPanel($this->getService(?));', ['tracy.bar', $this->prefix('panel')]);
		}
	}

}
