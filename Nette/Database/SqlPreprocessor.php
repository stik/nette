<?php

/**
 * This file is part of the Nette Framework.
 *
 * Copyright (c) 2004, 2010 David Grudl (http://davidgrudl.com)
 *
 * This source file is subject to the "Nette license", and/or
 * GPL license. For more information please see http://nette.org
 */

namespace Nette\Database;

use Nette;



/**
 * SQL preprocessor.
 *
 * @author     David Grudl
 */
class SqlPreprocessor extends Nette\Object
{
	/** @var Connection */
	private $connection;

	/** @var ISupplementalDriver */
	private $driver;

	/** @var array of input parameters */
	private $params;

	/** @var array of parameters to be processed by PDO */
	private $remaining;

	/** @var int */
	private $counter;

	/** @var string values|assoc|multi */
	private $arrayMode;



	public function __construct(Connection $connection)
	{
		$this->connection = $connection;
		$this->driver = $connection->getSupplementalDriver();
	}



	/**
	 * @param  string
	 * @param  array
	 * @return array of [sql, params]
	 */
	public function process($sql, $params)
	{
		$this->params = $params;
		$this->counter = 0;
		$this->remaining = array();

		$cmd = strtoupper(substr(ltrim($sql), 0, 6)); // detect array mode
		$this->arrayMode = $cmd === 'INSERT' || $cmd === 'REPLAC' ? 'values' : 'assoc';

		/*~
			\'.*?\'|".*?"|   ## string
			:[a-zA-Z0-9_]+:| ## :substitution:
			\?               ## placeholder
		~xs*/
		$sql = Nette\String::replace($sql, '~\'.*?\'|".*?"|:[a-zA-Z0-9_]+:|\?~s', array($this, 'callback'));

		while ($this->counter < count($params)) {
			$sql .= ' ' . $this->formatValue($params[$this->counter++]);
		}

		return array($sql, $this->remaining);
	}



	/** @internal */
	public function callback($m)
	{
		$m = $m[0];
		if ($m[0] === "'" || $m[0] === '"') { // string
			return $m;

		} elseif ($m[0] === '?') { // placeholder
			return $this->formatValue($this->params[$this->counter++]);

		} elseif ($m[0] === ':') { // substitution
			$s = substr($m, 1, -1);
			return isset($this->connection->substitutions[$s]) ? $this->connection->substitutions[$s] : $m;
		}
	}



	private function formatValue($value)
	{
		if (is_string($value)) {
			if (strlen($value) > 20) {
				$this->remaining[] = $value;
				return '?';

			} else {
				return $this->connection->quote($value);
			}

		} elseif (is_int($value)) {
			return (string) $value;

		} elseif (is_float($value)) {
			return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');

		} elseif (is_bool($value)) {
			return $value ? 1 : 0;

		} elseif ($value === NULL) {
			return 'NULL';

		} elseif (is_array($value)) {
			$vx = $kx = array();

			if (isset($value[0])) { // non-associative; value, value, value
				foreach ($value as $v) {
					$vx[] = $this->formatValue($v);
				}
				return implode(', ', $vx);

			} elseif ($this->arrayMode === 'values') { // (key, key, ...) VALUES (value, value, ...)
				$this->arrayMode = 'multi';
				foreach ($value as $k => $v) {
					$kx[] = $this->driver->delimite($k);
					$vx[] = $this->formatValue($v);
				}
				return '(' . implode(', ', $kx) . ') VALUES (' . implode(', ', $vx) . ')';

			} elseif ($this->arrayMode === 'assoc') { // key=value, key=value, ...
				foreach ($value as $k => $v) {
					$vx[] = $this->driver->delimite($k) . '=' . $this->formatValue($v);
				}
				return implode(', ', $vx);

			} elseif ($this->arrayMode === 'multi') { // multiple insert (value, value, ...), ...
				foreach ($value as $k => $v) {
					$vx[] = $this->formatValue($v);
				}
				return ', (' . implode(', ', $vx) . ')';
			}

		} elseif ($value instanceof \DateTime) {
			return $this->driver->formatDateTime($value);

		} elseif ($value instanceof SqlLiteral) {
			return $value;

		} else {
			$this->remaining[] = $value;
			return '?';
		}
	}

}
