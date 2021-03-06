<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\DI;

use Nette;
use Nette\DI\Definitions\Definition;


/**
 * Container builder.
 */
class ContainerBuilder
{
	use Nette\SmartObject;

	public const
		THIS_SERVICE = 'self',
		THIS_CONTAINER = 'container';

	/** @var array */
	public $parameters = [];

	/** @var Definition[] */
	private $definitions = [];

	/** @var array of alias => service */
	private $aliases = [];

	/** @var Autowiring */
	private $autowiring;

	/** @var bool */
	private $needsResolve = true;

	/** @var bool */
	private $resolving = false;

	/** @var array */
	private $dependencies = [];


	public function __construct()
	{
		$this->autowiring = new Autowiring($this);
		$this->addExternalDefinition(self::THIS_CONTAINER)->setType(Container::class);
	}


	/**
	 * Adds new service definition.
	 * @return Definitions\ServiceDefinition
	 */
	public function addDefinition(string $name, Definition $definition = null): Definition
	{
		$this->needsResolve = true;
		if (!$name) { // builder is not ready for falsy names such as '0'
			throw new Nette\InvalidArgumentException(sprintf('Service name must be a non-empty string, %s given.', gettype($name)));
		}
		$name = $this->aliases[$name] ?? $name;
		if (isset($this->definitions[$name])) {
			throw new Nette\InvalidStateException("Service '$name' has already been added.");
		}
		$lname = strtolower($name);
		foreach ($this->definitions as $nm => $foo) {
			if ($lname === strtolower((string) $nm)) {
				throw new Nette\InvalidStateException("Service '$name' has the same name as '$nm' in a case-insensitive manner.");
			}
		}
		if (!$definition) {
			$definition = new Definitions\ServiceDefinition;
		}
		$definition->setName($name);
		$definition->setNotifier(function (): void {
			$this->needsResolve = true;
		});
		return $this->definitions[$name] = $definition;
	}


	public function addAccessorDefinition(string $name): Definitions\AccessorDefinition
	{
		return $this->addDefinition($name, new Definitions\AccessorDefinition);
	}


	public function addFactoryDefinition(string $name): Definitions\FactoryDefinition
	{
		return $this->addDefinition($name, new Definitions\FactoryDefinition);
	}


	public function addLocatorDefinition(string $name): Definitions\LocatorDefinition
	{
		return $this->addDefinition($name, new Definitions\LocatorDefinition);
	}


	public function addExternalDefinition(string $name): Definitions\ExternalDefinition
	{
		return $this->addDefinition($name, new Definitions\ExternalDefinition);
	}


	/**
	 * Removes the specified service definition.
	 */
	public function removeDefinition(string $name): void
	{
		$this->needsResolve = true;
		$name = $this->aliases[$name] ?? $name;
		unset($this->definitions[$name]);
	}


	/**
	 * Gets the service definition.
	 */
	public function getDefinition(string $name): Definition
	{
		$service = $this->aliases[$name] ?? $name;
		if (!isset($this->definitions[$service])) {
			throw new MissingServiceException("Service '$name' not found.");
		}
		return $this->definitions[$service];
	}


	/**
	 * Gets all service definitions.
	 * @return Definition[]
	 */
	public function getDefinitions(): array
	{
		return $this->definitions;
	}


	/**
	 * Does the service definition or alias exist?
	 */
	public function hasDefinition(string $name): bool
	{
		$name = $this->aliases[$name] ?? $name;
		return isset($this->definitions[$name]);
	}


	public function addAlias(string $alias, string $service): void
	{
		if (!$alias) { // builder is not ready for falsy names such as '0'
			throw new Nette\InvalidArgumentException(sprintf('Alias name must be a non-empty string, %s given.', gettype($alias)));

		} elseif (!$service) { // builder is not ready for falsy names such as '0'
			throw new Nette\InvalidArgumentException(sprintf('Service name must be a non-empty string, %s given.', gettype($service)));

		} elseif (isset($this->aliases[$alias])) {
			throw new Nette\InvalidStateException("Alias '$alias' has already been added.");

		} elseif (isset($this->definitions[$alias])) {
			throw new Nette\InvalidStateException("Service '$alias' has already been added.");
		}
		$this->aliases[$alias] = $service;
	}


	/**
	 * Removes the specified alias.
	 */
	public function removeAlias(string $alias): void
	{
		unset($this->aliases[$alias]);
	}


	/**
	 * Gets all service aliases.
	 */
	public function getAliases(): array
	{
		return $this->aliases;
	}


	/**
	 * @param  string[]  $types
	 * @return static
	 */
	public function addExcludedClasses(array $types)
	{
		$this->needsResolve = true;
		$this->autowiring->addExcludedClasses($types);
		return $this;
	}


	/**
	 * Resolves service name by type.
	 * @param  bool  $throw exception if service doesn't exist?
	 * @throws ServiceCreationException
	 */
	public function getByType(string $type, bool $throw = false): ?string
	{
		if ($this->resolving) {
			throw new NotAllowedDuringResolvingException;
		} elseif ($this->needsResolve) {
			$this->resolve();
		}
		return $this->autowiring->getByType($type, $throw);
	}


	/**
	 * Gets the service definition of the specified type.
	 */
	public function getDefinitionByType(string $type): Definition
	{
		return $this->getDefinition($this->getByType($type, true));
	}


	/**
	 * Gets the service names and definitions of the specified type.
	 * @return Definition[]  service name is key
	 */
	public function findByType(string $type): array
	{
		if ($this->resolving) {
			throw new NotAllowedDuringResolvingException;
		} elseif ($this->needsResolve) {
			$this->resolve();
		}
		return $this->autowiring->findByType($type);
	}


	/**
	 * Gets the service objects of the specified tag.
	 * @return array of [service name => tag attributes]
	 */
	public function findByTag(string $tag): array
	{
		$found = [];
		foreach ($this->definitions as $name => $def) {
			if (($tmp = $def->getTag($tag)) !== null) {
				$found[$name] = $tmp;
			}
		}
		return $found;
	}


	/********************* building ****************d*g**/


	/**
	 * Checks services, resolves types and rebuilts autowiring classlist.
	 */
	public function resolve(): void
	{
		if ($this->resolving) {
			return;
		}
		$this->resolving = true;

		$resolver = new Resolver($this);
		foreach ($this->definitions as $def) {
			$resolver->resolveDefinition($def);
		}

		$this->autowiring->rebuild();

		$this->resolving = $this->needsResolve = false;
	}


	public function complete(): void
	{
		$this->resolve();
		foreach ($this->definitions as $def) {
			$def->setNotifier(null);
		}

		$resolver = new Resolver($this);
		foreach ($this->definitions as $def) {
			$resolver->completeDefinition($def);
		}
	}


	/**
	 * Adds item to the list of dependencies.
	 * @param  \ReflectionClass|\ReflectionFunctionAbstract|string  $dep
	 * @return static
	 * @internal
	 */
	public function addDependency($dep)
	{
		$this->dependencies[] = $dep;
		return $this;
	}


	/**
	 * Returns the list of dependencies.
	 */
	public function getDependencies(): array
	{
		return $this->dependencies;
	}


	/**
	 * @internal
	 */
	public function exportMeta(): array
	{
		$meta[Container::TYPES] = $this->autowiring->getClassList();

		$defs = $this->definitions;
		ksort($defs);
		foreach ($defs as $name => $def) {
			$meta[Container::SERVICES][$name] = $def->getType();
			foreach ($def->getTags() as $tag => $value) {
				$meta[Container::TAGS][$tag][$name] = $value;
			}
		}

		$meta[Container::ALIASES] = $this->aliases;
		ksort($meta[Container::ALIASES]);

		return $meta;
	}


	public static function literal(string $code, array $args = null): Nette\PhpGenerator\PhpLiteral
	{
		return new Nette\PhpGenerator\PhpLiteral(
			$args === null ? $code : Nette\PhpGenerator\Helpers::formatArgs($code, $args)
		);
	}


	/** @deprecated */
	public function formatPhp(string $statement, array $args): string
	{
		array_walk_recursive($args, function (&$val): void {
			if ($val instanceof Statement) {
				$val = (new Resolver($this))->completeStatement($val);

			} elseif ($val instanceof Definition) {
				$val = new Definitions\Reference($val->getName());
			}
		});
		return (new PhpGenerator($this))->formatPhp($statement, $args);
	}


	/** @deprecated use resolve() */
	public function prepareClassList(): void
	{
		trigger_error(__METHOD__ . '() is deprecated, use resolve()', E_USER_DEPRECATED);
		$this->resolve();
	}
}
