<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace KdybyTests;

use Kdyby;
use Nette;
use Tester;



abstract class IntegrationTestCase extends Tester\TestCase
{

	/**
	 * @param string $model
	 * @return \SystemContainer|Nette\DI\Container
	 */
	protected function createContainer($model = NULL)
	{
		$rootDir = __DIR__ . '/../';

		$config = new Nette\Configurator();
		$config->setTempDirectory(TEMP_DIR);
		$config->addParameters(array('container' => array('class' => 'SystemContainer_' . md5($model ? : time()))));
		$config->addParameters(array('appDir' => $rootDir, 'wwwDir' => $rootDir));
		$config->addConfig(__DIR__ . '/nette-reset.neon', !isset($config->defaultExtensions['nette']) ? 'v23' : 'v22');
		if ($model) {
			$config->addConfig(__DIR__ . '/DoctrineMoney/config/' . $model . '.neon', $config::NONE);
			require_once __DIR__ . '/DoctrineMoney/models/' . $model . '.php';
		}
		Kdyby\DoctrineMoney\DI\MoneyExtension::register($config);

		return $config->createContainer();
	}

}
