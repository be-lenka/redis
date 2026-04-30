<?php

namespace Venalio\Redis\Session\Handlers;

use Predis\Session\Handler;

class CustomSessionHandler extends Handler implements \SessionUpdateTimestampHandlerInterface
{
	const NS_SESSION = 'session:';

	public function read($session_id)
	{
		$sessionKey = $this->formatKey($session_id);
		if ($data = $this->client->get($sessionKey)) {
			return $data;
		}

		return '';
	}

	public function write($session_id, $session_data)
	{
		$sessionKey = $this->formatKey($session_id);
		$this->client->setex($sessionKey, $this->ttl, $session_data);

		return TRUE;
	}

	public function destroy($session_id)
	{
		$sessionKey = $this->formatKey($session_id);
		$this->client->del([$sessionKey]);

		return TRUE;
	}

	public function formatKey($id)
	{
		return self::NS_SESSION . $id;
	}

	/**
	 * Required for PHP's session.use_strict_mode (which Nette enables by default).
	 * Without it, PHP rejects every incoming session ID and regenerates one, so
	 * authenticated sessions cannot survive across requests.
	 */
	public function validateId($session_id): bool
	{
		return (bool) $this->client->exists($this->formatKey($session_id));
	}

	public function updateTimestamp($session_id, $session_data): bool
	{
		$sessionKey = $this->formatKey($session_id);
		$this->client->expire($sessionKey, $this->ttl);

		return TRUE;
	}
}