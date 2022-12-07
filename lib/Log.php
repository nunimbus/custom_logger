<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Andrew Summers
 *
 * @author Andrew Summers
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\CustomLogger;

include('Php2Curl.php');
use Nextcloud\LogNormalizer\Normalizer;
use OCP\Log\IDataLogger;
use function array_merge;
use OC\Log\ExceptionSerializer;
use OCP\ILogger;
use OCP\Log\IFileBased;
use OCP\Log\IWriter;
use OCP\Support\CrashReport\IRegistry;
use OC\SystemConfig;
use OC\Log as SysLog;
use \Php2Curl\Php2Curl;
use OC_App;
use function strtr;

/**
 * logging utilities
 *
 * This is a stand in, this should be replaced by a Psr\Log\LoggerInterface
 * compatible logger. See https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md
 * for the full interface specification.
 *
 * MonoLog is an example implementing this interface.
 */
class Log extends SysLog implements ILogger, IDataLogger {

	/** @var IWriter */
	private $logger;

	/** @var SystemConfig */
	private $config;

	/** @var boolean|null cache the result of the log condition check for the request */
	private $logConditionSatisfied = null;

	/** @var Normalizer */
	private $normalizer;

	/** @var IRegistry */
	private $crashReporters;

	private $sensitive = array();

	/**
	 * @param IWriter $logger The logger that should be used
	 * @param SystemConfig $config the system config object
	 * @param Normalizer|null $normalizer
	 * @param IRegistry|null $registry
	 */
	public function __construct(IWriter $logger, SystemConfig $config = null, $normalizer = null, IRegistry $registry = null) {
		parent::__construct($logger, $config, $normalizer, $registry);

		// FIXME: Add this for backwards compatibility, should be fixed at some point probably
		if ($config === null) {
			$config = \OC::$server->getSystemConfig();
		}

		$this->config = $config;
		$this->logger = $logger;
		if ($normalizer === null) {
			$this->normalizer = new Normalizer();
		} else {
			$this->normalizer = $normalizer;
		}
		$this->crashReporters = $registry;

		$this->sensitive = [
			\OC::$server->getConfig()->getSystemValue('secret'),
			\OC::$server->getConfig()->getSystemValue('passwordsalt'),
			\OC::$server->getConfig()->getSystemValue('dbpassword'),
		];

		if (
			\OC::$server->getUserSession()->isLoggedIn() &&
			\OC::$server->getUserSession()->getUser()->getBackendClassName() == "user_saml" &&
			$secret = \OC::$server->query('OCA\User_SAML\UserBackend')->getCurrentUserSecret()
		) {
				array_push($this->sensitive, $secret);
		}
	}

	/**
	 * @param string $app
	 * @param string|array $entry
	 * @param int $level
	 */
	protected function writeLog(string $app, $entry, int $level) {
		$serializer = new ExceptionSerializer($this->config);
		$placeholder = $serializer::SENSITIVE_VALUE_PLACEHOLDER;

		foreach ($this->sensitive as $val) {
			if (is_array($entry)) {
				if (isset($entry['Message']) || array_key_exists('Message', $entry)) {
					$entry['Message'] = str_replace($val, $placeholder, $entry['Message']);
				}
				else if (isset($entry['message']) || array_key_exists('message', $entry)) {
					$entry['message'] = str_replace($val, $placeholder, $entry['message']);
				}
			}
			else {
				$entry = str_replace($val, $placeholder, $entry);
			}
		}

		$pathPrefixes = [
			'/var/www/html/config/',
		];

		foreach (OC_App::getEnabledApps() as $appId) {
			if (\OC::$server->getAppManager()->isShipped($appId)) {
				continue;
			}

			$appInfo = \OC::$server->getAppManager()->getAppInfo($appId);

			if (! (isset($appInfo['repository']) || array_key_exists('repository', $appInfo)) ||
				! (isset($appInfo['repository']['@value']) || array_key_exists('@value', $appInfo['repository']))
			) {
				$appPath = \OC::$server->getAppManager()->getAppPath($appId);
				array_push($pathPrefixes, $appPath);
			}
		}

		$break = false;
		$silence = false;

		if (isset($entry['Trace']) || array_key_exists('Trace', $entry)) {
			foreach ($entry['Trace'] as $tr) {
				if (
					(isset($tr['file']) || array_key_exists('file', $tr)) &&
					(isset($tr['line']) || array_key_exists('line', $tr))
				) {
					$line = file($tr['file'])[$tr['line'] - 1];

					if (str_contains($line, '@')) {
						$silence = true;
						break;
					}
				}

				// If this is a custom-written app, create a cURL dump of the current request and log it to `custom_apps.log`
				foreach ($pathPrefixes as $prefix) {
					if (isset($tr['file']) || array_key_exists('file', $tr)) {
						if (str_starts_with($tr['file'], $prefix)) {
							$logJson = $this->logger->logDetailsAsJSON($app, $entry, $level);
							$logArray = json_decode($logJson, true);
							$php2curl = new Php2Curl();
							$logArray['curl'] = $php2curl->doAll();
							$logJson = json_encode($logArray);

							// tail -n 1 data/custom_apps.log | jq ".curl" | sed 's/\\"/"/g' | sed 's/\(^"\|"$\)//g'
							file_put_contents('/var/www/html/data/custom_apps.log', $logJson . "\n", FILE_APPEND);
							$break = true;
							break;
						}
					}
				}
				if ($break) break;
			}
		}

		if (! $silence || OC::$server->getConfig()->getSystemValue('loglevel', 2) <= 1) {
			$this->logger->write($app, $entry, $level);
		}
	}
}