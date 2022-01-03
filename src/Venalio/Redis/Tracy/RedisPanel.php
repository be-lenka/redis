<?php declare(strict_types=1);

namespace Venalio\Redis\Tracy;

use Throwable;
use Tracy\Debugger;
use Tracy\IBarPanel;

final class RedisPanel implements IBarPanel
{
	const TIMER_NAME = 'redis_timer';
	const TIME_UNIT = 'ms';

	/** @var mixed[] */
	private $connections;

	/** @var array */
	private $commands = [];

	/** @var int */
	private $totalCommands = 0;

	/** @var int */
	private $maxCommands = 1000;

	/** @var float */
	private $totalTime = 0.0;

	/** @var int */
	private $totalSize = 0;

	/**
	 * @param mixed[] $connections
	 */
	public function __construct()
	{
		$this->connections = [];
	}

	public function addConnection($connection)
	{
		$this->connections[] = $connection;
	}

	public function commandStart($cmd, $params)
	{
		Debugger::timer(self::TIMER_NAME);
	}

	public function commandEnd($connectionName, $cmd, $params, $data = NULL)
	{
		$cmd = strtoupper($cmd);
		if ($cmd == 'SET' || $cmd == 'SETEX' || $cmd == 'SETNX' || $cmd == 'GET') {
			$key = reset($params);
		} else {
			$key = json_encode($params);
		}
		$key = is_string($key) ? $key : json_encode($key);

		$time = round(Debugger::timer(self::TIMER_NAME) * 1000.0, 3);
		if ($this->totalCommands < $this->maxCommands) {
			$dataSize = '-';
			if ($cmd == 'GET' && $data && is_string($data)) {
				$this->totalSize += $size = strlen($data);
				$dataSize = self::bytes($size);
			}

			$this->commands[] = [$connectionName, $cmd, $key, $time . ' ' . self::TIME_UNIT, $dataSize];
		}
		$this->totalCommands++;
		$this->totalTime += $time;
	}

	/**
	 * Renders HTML code for custom tab.
	 */
	public function getTab(): string
	{
		return '<span title="Redis panel">'
			. '<img style="height:18px;margin:1px 0;top:0;" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAYAAACNiR0NAAADm0lEQVQ4T61US2icVRT+zrn/YzKT5jWZTNIInSQzUxskULDRurFrxa4ki4oILUUURagi6EYQRHBhEUSqtHaRRbTbSjcuxIWVVFOfNWqGpKWxNo/JaybTztz/3ivnTwyN2mbj2fxwz3++c7/vO+cS/uegnfC+OHTI271041EAfeS5r4qXpybvVXNXwKn9+UFr+ahlfjro6e3Sf96AammFLi9fUp47zaH9tDBeWvsn+DbAX/fu3YUQIwCOgXAQREg+OIzuN98GJ5NYOTeG8kcfwGkNWNTAGLOOT+/7cXKcACfg5ACaGioMW+LjxuJIamioKcwXAOdwe/IKGteuws92o/PFEyif+RD6+jVU1qpY1BEYDveFPiwwTUynKKCPaeKBwqWU4gOC3vL4YfS89c42FtefPYra+EWQUogig9mGRdU4kDP4drWCYjKBA20pKAArUfQbfdLfN5MNVC4TKHhKIXXwEYT5IuAsbv9yBbe+vwy/ZzeCvn6YSgW17yawbhyqxmKXIqQUYzWyuNmwWIvsBI3u6f+BGEMMoN1nZAJGs9qQNrx/EF0nXkVy+GHY9XVcffIJiDkSxgGLDYv5hkE9Vk8ExCn6ZrD4xpKOXlk21By5jUySCV0ho8NnSKOwUITqSKM2/jXq1mGuYVFuWJgYA2jzCZ2+ch7cebpQGLjZm1DZgAnLkcV8fUMjCY+BjMdo9xUiuDgn9CQrJDp9hWxA8JlQ1hazdf0TjeZyJWYeaFWMroDQ4jFqxmFeWyxpB7t567+dSjAhGzLSHsW0F7TFQsNCSxdnL9L5Yv61qtavG6hmKZKCjM9IB0IWKGsTAyuiuGFr3NBiru5iRoIjf7YFVGv1+CR9ua842xVQ72rksNAwkK8EEyHtCwijiTdMWtain9mSRHpKXqhr6/BHXZdiygnmgUyo0OlzLLRotagtzCbdJgVEjuIiCZkCAZKpqEQ2NmktcrBwEzSa63+XCC/JpeRWHd6GwwkiLIlJDRtrKtHuE7oDhSYGypGLc7c2c7DmZzC/HHMZy+Vyhvk5OHcMoLScpRRBhl1oy6h4sqwOmItNcFu3d85eIKL3npqZ+Vx+2fY4nM3lEh6pEYJ7HsBDAuwTxXMm0q5qK3sLa02Vmc8aovefmZ7+/c5dvevzNbYnv9+QfQGEI2J+XGTNrFPqZAicGZmeXt229FvL8l+nd5ydy+cz2pjDlmglzfzZY6VS/V4lO77YO/T7V/ovpDmUCVPkVDYAAAAASUVORK5CYII="/>'
			. '<span class="tracy-label">' . $this->totalCommands . 'x / ' . round($this->totalTime, 1) . ' ' . self::TIME_UNIT . '</span>'
			. '</span>';
	}

	/**
	 * Renders HTML code for custom panel.
	 */
	public function getPanel(): string
	{
		ob_start();

		$connections = $this->connections;
		$commands = $this->commands;
		$maxCommands = $this->maxCommands;
		$totalCommands = $this->totalCommands;
		$totalSize = self::bytes($this->totalSize);
		$totalTime = $this->totalTime . ' ' . self::TIME_UNIT;

		foreach ($connections as $key => $connection) {
			$uri = $connection['uri'];
			$isCluster = is_array($uri);

			$start = microtime(TRUE);
			try {
				$connections[$key]['ping'] = $connection['client']->ping('ok');
			} catch (Throwable $e) {
				$connections[$key]['ping'] = 'failed';
			} finally {
				$connections[$key]['duration'] = (microtime(TRUE) - $start) * 1000;
			}

			if ($isCluster) {
				$connections[$key]['dbSize'] = FALSE;
			} else {
				try {
					$connections[$key]['dbSize'] = $connection['client']->dbsize();
				} catch (Throwable $e) {
					$connections[$key]['dbSize'] = $e->getMessage();
				}
			}
		}

		require __DIR__ . '/templates/panel.phtml';

		return (string) ob_get_clean();
	}

	private static function bytes($bytes, $precision = 2)
	{
		$bytes = round($bytes);
		$units = ['B', 'kiB', 'MiB', 'GiB', 'TiB', 'PiB'];
		foreach ($units as $unit) {
			if (abs($bytes) < 1024 || $unit === end($units)) {
				break;
			}
			$bytes = $bytes / 1024;
		}

		return round($bytes, $precision) . ' ' . $unit;
	}

}
