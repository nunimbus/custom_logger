<?php
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

namespace OCA\CustomLogger\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\CustomLogger\Log;

use OC;
use OC\Server;
use OC\SystemConfig;
use OC\AllConfig;
use OC\Log\LogFactory;
use OCP\Support\CrashReport\IRegistry;

class Application extends App implements IBootstrap {

	public const APP_ID = 'custom_logger';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		\OC::$server->registerService(\OC\Log::class, function (Server $c) {
			$logType = $c->get(AllConfig::class)->getSystemValue('log_type', 'file');
			$factory = new LogFactory($c, $c->get(SystemConfig::class));
			$logger = $factory->get($logType);
			$registry = $c->get(IRegistry::class);

			return new Log($logger, $c->get(SystemConfig::class), null, $registry);
		});
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
	}
}
