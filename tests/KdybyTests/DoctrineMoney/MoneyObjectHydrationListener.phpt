<?php

/**
 * Test: Kdyby\DoctrineMoney\MoneyObjectHydrationListener.
 *
 * @testCase KdybyTests\DoctrineMoney\MoneyObjectHydrationListenerTest
 * @author Filip Procházka <filip@prochazka.su>
 * @package Kdyby\DoctrineMoney
 */

namespace KdybyTests\DoctrineMoney;

use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Kdyby\Money\Currency;
use Kdyby\Money\Money;
use Kdyby\Money\NullCurrency;
use Kdyby;
use Kdyby\Doctrine\Events;
use Nette;
use Tester\Assert;
use Tester;



require_once __DIR__ . '/../bootstrap.php';



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class MoneyObjectHydrationListenerTest extends \KdybyTests\IntegrationTestCase
{

	/**
	 * @dataProvider dataEntityClasses
	 */
	public function testFunctional($className)
	{
		$container = $this->createContainer('order');

		/** @var Kdyby\Doctrine\EntityManager $em */
		$em = $container->getByType('Kdyby\Doctrine\EntityManager');

		$class = $em->getClassMetadata($className);

		// assert that listener was binded to entity
		Assert::same(array(
			Events::postLoadRelations => array(array('class' => 'Kdyby\\DoctrineMoney\\Mapping\\MoneyObjectHydrationListener', 'method' => Events::postLoadRelations)),
		), $class->entityListeners);

		// generate schema
		$schema = new SchemaTool($em);
		$schema->createSchema($em->getMetadataFactory()->getAllMetadata());

		// test money hydration
		$em->persist(new $className(1000, 'CZK'))->flush()->clear();

		$currencies = $em->getRepository(Kdyby\Money\Currency::getClassName());

		/** @var OrderEntity $order */
		$order = $em->find($className, 1);
		Assert::equal(new Kdyby\Money\Money(1000, $currencies->find('CZK')), $order->getMoney());
	}



	public function dataEntityClasses()
	{
		return array(
			array(OrderEntity::getClassName()),
			array(SpecificOrderEntity::getClassName()),
		);
	}



	/**
	 * @dataProvider dataHydratedEntities
	 */
	public function testNullCurrencyHydration($expectedMoney, OrderEntity $entity)
	{
		$container = $this->createContainer('order');
		/** @var Kdyby\Doctrine\EntityManager $em */
		$em = $container->getByType('Kdyby\Doctrine\EntityManager');
		/** @var \Kdyby\DoctrineMoney\Mapping\MoneyObjectHydrationListener $listener */
		$listener = $container->getByType('Kdyby\DoctrineMoney\Mapping\MoneyObjectHydrationListener');

		$listener->postLoadRelations($entity, new LifecycleEventArgs($entity, $em));

		Assert::equal($expectedMoney, $entity->getMoney());
	}



	public function dataHydratedEntities()
	{
		$czk = new Currency('CZK', 100);
		$null = new NullCurrency();

		return array(
			array(new Money(100, $czk), new OrderEntity(100, $czk)),
			array(new Money(0, $czk), new OrderEntity(0, $czk)),
			array(new Money(0, $null), new OrderEntity(0, new NullCurrency())),
			array(new Money(100, $null), new OrderEntity(100, NULL)),
			array(new Money(0, $null), new OrderEntity(0, NULL)),
		);
	}



	public function testRepeatedLoading()
	{
		$container = $this->createContainer('order');
		/** @var Kdyby\Doctrine\EntityManager $em */
		$em = $container->getByType('Kdyby\Doctrine\EntityManager');
		$class = $em->getClassMetadata(OrderEntity::getClassName());

		// generate schema
		$schema = new SchemaTool($em);
		$schema->createSchema($em->getMetadataFactory()->getAllMetadata());

		// test money hydration
		$em->persist(new OrderEntity(1000, 'CZK'));
		$em->flush();
		$em->clear();

		$currencies = $em->getRepository(Kdyby\Money\Currency::getClassName());

		/** @var OrderEntity $order */
		$order = $em->find(OrderEntity::getClassName(), 1);
		Assert::equal(new Kdyby\Money\Money(1000, $currencies->find('CZK')), $order->getMoney());

		// following loading should not fail
		$order2 = $em->createQueryBuilder("o")
			->select("o")
			->from(OrderEntity::getClassName(), "o")
			->where("o.id = :id")->setParameter("id", 1)
			->getQuery()->getSingleResult();
		Assert::same($order, $order2);
	}

}

\run(new MoneyObjectHydrationListenerTest());
