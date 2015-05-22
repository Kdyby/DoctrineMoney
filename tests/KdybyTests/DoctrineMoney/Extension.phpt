<?php

/**
 * Test: Kdyby\Money\Extension.
 *
 * @testCase Kdyby\Money\ExtensionTest
 * @author Filip Procházka <filip@prochazka.su>
 * @package Kdyby\Money
 */

namespace KdybyTests\DoctrineMoney;

use Doctrine\DBAL\Types\Type;
use Kdyby;
use Nette;
use Tester;
use Tester\Assert;



require_once __DIR__ . '/../bootstrap.php';



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class ExtensionTest extends \KdybyTests\IntegrationTestCase
{

	public function testRegisterTypes()
	{
		$container = $this->createContainer();
		/** @var \Kdyby\Doctrine\Connection $connection */
		$connection = $container->getByType('Kdyby\Doctrine\Connection');
		$connection->connect(); // initializes the types

		Assert::true(Type::getType('money') instanceof Kdyby\DoctrineMoney\Types\Money);
	}

}

\run(new ExtensionTest());
