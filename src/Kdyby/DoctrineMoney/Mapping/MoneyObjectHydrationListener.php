<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\DoctrineMoney\Mapping;

use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Kdyby;
use Kdyby\Money\MetadataException;
use Kdyby\Money\Money;
use Kdyby\Money\NullCurrency;
use Nette;
use Nette\Utils\Json;



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class MoneyObjectHydrationListener extends Nette\Object implements Kdyby\Events\Subscriber
{

	/**
	 * @var \Doctrine\Common\Cache\CacheProvider
	 */
	private $cache;

	/**
	 * @var \Doctrine\ORM\EntityManager
	 */
	private $entityManager;

	/**
	 * @var \Doctrine\Common\Annotations\Reader
	 */
	private $annotationReader;

	/**
	 * @var array
	 */
	private $moneyFieldsCache = array();



	public function __construct(CacheProvider $cache, Reader $annotationReader, EntityManager $entityManager)
	{
		$this->cache = $cache;
		$this->cache->setNamespace(get_called_class());
		$this->entityManager = $entityManager;
		$this->annotationReader = $annotationReader;
	}



	public function getSubscribedEvents()
	{
		return array(
			Events::loadClassMetadata,
		);
	}



	public function postLoadRelations($entity, LifecycleEventArgs $args)
	{
		if (!$fieldsMap = $this->getEntityMoneyFields($entity)) {
			return;
		}

		/** @var ClassMetadata[]|string[] $mapping */
		foreach ($fieldsMap as $moneyField => $mapping) {
			$amount = $mapping['moneyFieldClass']->getFieldValue($entity, $moneyField);
			if ($amount instanceof Money || $amount === NULL) {
				continue;
			}

			$currency = $mapping['currencyClass']->getFieldValue($entity, $mapping['currencyAssociation']);
			if (!$currency instanceof Kdyby\Money\Currency) {
				$currency = new NullCurrency();
			}

			$mapping['moneyFieldClass']->setFieldValue($entity, $moneyField, Money::from($amount, $currency));
		}
	}



	/**
	 * @todo: always force currency from money object to currencyAssoc that is referenced in metadata
	 * @todo: when more that one money object is referring to same currencyAssoc, than all of them mus have same currency
	 */
	public function preFlush($entity, PreFlushEventArgs $args)
	{
		if (!$fieldsMap = $this->getEntityMoneyFields($entity)) {
			return;
		}

		/** @var ClassMetadata[]|string[] $mapping */
		foreach ($fieldsMap as $moneyField => $mapping) {
			$amount = $mapping['moneyFieldClass']->getFieldValue($entity, $moneyField);
			if ($amount === NULL) {
				continue;
			}

		}
	}



	public function loadClassMetadata(LoadClassMetadataEventArgs $args)
	{
		$class = $args->getClassMetadata();
		if (!$class instanceof ClassMetadata || $class->isMappedSuperclass || !$class->getReflectionClass()->isInstantiable()) {
			return;
		}

		$currencyMetadata = $class->getName() === 'Kdyby\Money\Currency' ? $class : $this->entityManager->getClassMetadata('Kdyby\Money\Currency');
		$idColumn = $currencyMetadata->getSingleIdentifierColumnName();

		foreach ($class->getAssociationNames() as $assocName) {
			if ($class->getAssociationTargetClass($assocName) !== 'Kdyby\Money\Currency') {
				continue;
			}

			$mapping = $class->getAssociationMapping($assocName);
			foreach ($mapping['joinColumns'] as &$join) {
				$join['referencedColumnName'] = $idColumn;
			}

			$class->setAssociationOverride($assocName, $mapping);
		}

		if (!$this->buildMoneyFields($class)) {
			return;
		}

		if (!$this->hasRegisteredListener($class, Kdyby\Doctrine\Events::postLoadRelations, get_called_class())) {
			$class->addEntityListener(Kdyby\Doctrine\Events::postLoadRelations, get_called_class(), Kdyby\Doctrine\Events::postLoadRelations);
		}

		if (!$this->hasRegisteredListener($class, Events::preFlush, get_called_class())) {
			$class->addEntityListener(Events::preFlush, get_called_class(), Events::preFlush);
		}
	}



	private function getEntityMoneyFields($entity, ClassMetadata $class = NULL)
	{
		$class = $class ?: $this->entityManager->getClassMetadata(get_class($entity));

		if (isset($this->moneyFieldsCache[$class->name])) {
			return $this->moneyFieldsCache[$class->name];
		}

		if ($this->cache->contains($class->getName())) {
			$moneyFields = Json::decode($this->cache->fetch($class->getName()), Json::FORCE_ARRAY);

		} else {
			$moneyFields = $this->buildMoneyFields($class);
			$this->cache->save($class->getName(), $moneyFields ? Json::encode($moneyFields) : FALSE);
		}

		if (is_array($moneyFields) && !empty($moneyFields)) {
			foreach ($moneyFields as &$mapping) {
				$mapping['moneyFieldClass'] = $this->entityManager->getClassMetadata($mapping['moneyFieldClass']);
				$mapping['currencyClass'] = $this->entityManager->getClassMetadata($mapping['currencyClass']);
			}
		}

		return $this->moneyFieldsCache[$class->getName()] = $moneyFields;
	}



	private function buildMoneyFields(ClassMetadata $class)
	{
		$moneyFields = array();

		foreach ($class->getFieldNames() as $fieldName) {
			$mapping = $class->getFieldMapping($fieldName);
			if ($mapping['type'] !== Kdyby\DoctrineMoney\Types\Money::MONEY) {
				continue;
			}

			$classRefl = $class->isInheritedField($fieldName) ? new \ReflectionClass($mapping['declared']) : $class->getReflectionClass();
			$property = $classRefl->getProperty($fieldName);
			$column = $this->annotationReader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\Column');

			if (empty($column->options['currency'])) {
				if ($class->hasAssociation('currency')) {
					$column->options['currency'] = 'currency'; // default association name

				} else {
					throw MetadataException::missingCurrencyReference($property);
				}
			}

			$currencyAssoc = $column->options['currency'];
			if (!$class->hasAssociation($currencyAssoc)) {
				throw MetadataException::invalidCurrencyReference($property);
			}

			$moneyFields[$fieldName] = array(
				'moneyFieldClass' => $classRefl->getName(),
				'currencyClass' => $class->isInheritedAssociation($currencyAssoc) ? $class->associationMappings[$currencyAssoc]['declared'] : $class->getName(),
				'currencyAssociation' => $currencyAssoc,
			);
		}

		return $moneyFields;
	}



	private static function hasRegisteredListener(ClassMetadata $class, $eventName, $listenerClass)
	{
		if (!isset($class->entityListeners[$eventName])) {
			return FALSE;
		}

		foreach ($class->entityListeners[$eventName] as $listener) {
			if ($listener['class'] === $listenerClass && $listener['method'] === $eventName) {
				return TRUE;
			}
		}

		return FALSE;
	}

}
