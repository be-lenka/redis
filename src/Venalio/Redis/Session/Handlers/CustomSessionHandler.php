<?php

namespace Venalio\Redis\Session\Handlers;

use Predis\Session\Handler;

class CustomSessionHandler extends Handler
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
}