<?php

use Prodrivers\EnvVar\EnvNotFoundException;

/**
 * Pico plugin that replace environment variables in configuration with their values, with Symfony's %env()% syntax.
 *
 * The parsing code is extracted from Symfony's Dependency Injection EnvVarProcessor and adapted to work standalone.
 *
 * @author  Horgeon
 * @version 1.0
 */

final class EnvironmentVariableInConfiguration extends AbstractPicoPlugin
{
	/**
	 * API version used by this plugin
	 *
	 * @var int
	 */
	public const API_VERSION = 3;

	/**
	 * This plugin depends on ...
	 *
	 * @see AbstractPicoPlugin::$dependsOn
	 * @var string[]
	 */
	protected $dependsOn = array();

	/**
	 * Triggered after Pico has read its configuration
	 *
	 * @see Pico::getConfig()
	 * @see Pico::getBaseUrl()
	 * @see Pico::isUrlRewritingEnabled()
	 *
	 * @param array &$config array of config variables
	 */
	public function onConfigLoaded(array &$config)
	{
		// Special processing for base_url, as it always finishes with /
		$recompute_base_url = false;
		if ($config['base_url']) {
			$config['base_url'] = rtrim($config['base_url'], '/');
			$recompute_base_url = true;
		}

		$this->exploreConfigurationArray($config);

		// Recompute base_url, consider special value 'null' in order to make base_url work with environment variables
		if (!$config['base_url'] || $config['base_url'] == 'null') {
			unset($config['base_url']);
			$config['base_url'] = $this->getBaseUrl();
		} else {
			// Trim / on the right and add one to only have one /
			$config['base_url'] = rtrim($config['base_url'], '/') . '/';
		}

		// If necessary, recompute values that are dependent on base_url
		if ($recompute_base_url) {
			$config['plugins_url'] = $this->getUrlFromPath($this->getPluginsDir());
			$config['themes_url'] = $this->getUrlFromPath($this->getThemesDir());
			$config['assets_url'] = $this->getUrlFromPath($config['assets_dir']);
		}
	}

	/**
	 * Recursively explore configuration values
	 *
	 * @param array &$config Configuration or sub-configuration array reference
	 */
	private function exploreConfigurationArray(array &$config)
	{
		foreach ($config as &$value) {
			if (is_array($value)) {
				$this->exploreConfigurationArray($value);
			} else {
				$newValue = $this->parse($value);
				if ($newValue !== null) {
					$value = $newValue;
				}
			}
		}
	}

	/**
	 * Get the resulting value of an environment variable syntax from a configuration value
	 *
	 * @param $value Configuration value to parse
	 * @return mixed|undefined Parsed value or null if there is no environment variable syntax
	 */
	private function parse($value)
	{
		if ($value !== null && 0 === strpos($value, '%env(') && ')%' === substr($value, -2) && '%env()%' !== $value) {
			$env = substr($value, 5, -2);

			if (!preg_match('/^(?:[-.\w]*+:)*+\w++$/', $env)) {
				throw new InvalidArgumentException(sprintf('Invalid %s value: only "word" characters are allowed.', $value));
			}

			return $this->parseEnv($env);
		}

		return null;
	}

	/**
	 * Parse an environment variable syntax and return the computed value
	 *
	 * @param $name Environment variable syntax to parse
	 * @return mixed Parsed value
	 */
	private function parseEnv($name)
	{
		$i = strpos($name, ':');
		if ($i !== false) {
			$values = explode(':', $name);
			$prefix = $values[0];
			$value = $values[1];
		} else {
			$prefix = null;
			$value = $name;
		}

		if ('key' === $prefix) {
			$name = str_replace('key:', '', $name);
			$i = strpos($name, ':');
			if (false === $i) {
				throw new RuntimeException(sprintf('Invalid env "key:%s": a key specifier should be provided.', $name));
			}

			$next = substr($name, $i + 1);
			$key = substr($name, 0, $i);
			$array = $this->parseEnv($next);

			if (!\is_array($array)) {
				throw new RuntimeException(sprintf('Resolved value of "%s" did not result in an array value.', $next));
			}

			if (!isset($array[$key]) && !\array_key_exists($key, $array)) {
				throw new EnvNotFoundException(sprintf('Key "%s" not found in %s (resolved from "%s").', $key, json_encode($array), $next));
			}

			return $array[$key];
		}

		if ('default' === $prefix) {
			$name = str_replace('default:', '', $name);
			$i = strpos($name, ':');
			if (false === $i) {
				throw new RuntimeException(sprintf('Invalid env "default:%s": a fallback parameter should be provided.', $name));
			}

			$next = substr($name, $i + 1);
			$default = substr($name, 0, $i);

			if ('' === $default) {
				throw new RuntimeException(sprintf('Invalid env fallback in "default:%s": parameter "%s" not found.', $name, $default));
			}

			try {
				$env = $this->parseEnv($next);

				if ('' !== $env && null !== $env) {
					return $env;
				}
			} catch (EnvNotFoundException $e) {
				// no-op
			}

			return '' === $default ? null : $default;
		}

		if ('file' === $prefix || 'require' === $prefix) {
			if (!is_scalar($file = $getEnv($name))) {
				throw new RuntimeException(sprintf('Invalid file name: env var "%s" is non-scalar.', $name));
			}
			if (!is_file($file)) {
				throw new EnvNotFoundException(sprintf('File "%s" not found (resolved from "%s").', $file, $name));
			}

			if ('file' === $prefix) {
				return file_get_contents($file);
			} else {
				return require $file;
			}
		}

		if (isset($_ENV[$value])) {
			$env = $_ENV[$value];
		} elseif (isset($_SERVER[$value]) && 0 !== strpos($value, 'HTTP_')) {
			$env = $_SERVER[$value];
		} elseif (false === ($env = \getenv($value)) || null === $env) { // null is a possible value because of thread safety issues
			throw new EnvNotFoundException(sprintf('Environment variable not found: "%s".', $value));
		}

		if (!is_scalar($env)) {
			throw new RuntimeException(sprintf('Non-scalar env var "%s" cannot be cast to "%s".', $name, $prefix));
		}

		if (null === $prefix) {
			return (string) $env;
		}

		if ('string' === $prefix) {
			return (string) $env;
		}

		if (in_array($prefix, ['bool', 'not'], true)) {
			$env = (bool) (filter_var($env, \FILTER_VALIDATE_BOOLEAN) ?: filter_var($env, \FILTER_VALIDATE_INT) ?: filter_var($env, \FILTER_VALIDATE_FLOAT));

			return 'not' === $prefix ? !$env : $env;
		}

		if ('int' === $prefix) {
			if (false === $env = filter_var($env, \FILTER_VALIDATE_INT) ?: filter_var($env, \FILTER_VALIDATE_FLOAT)) {
				throw new RuntimeException(sprintf('Non-numeric env var "%s" cannot be cast to int.', $name));
			}

			return (int) $env;
		}

		if ('float' === $prefix) {
			if (false === $env = filter_var($env, \FILTER_VALIDATE_FLOAT)) {
				throw new RuntimeException(sprintf('Non-numeric env var "%s" cannot be cast to float.', $name));
			}

			return (float) $env;
		}

		if ('const' === $prefix) {
			if (!\defined($env)) {
				throw new RuntimeException(sprintf('Env var "%s" maps to undefined constant "%s".', $name, $env));
			}

			return \constant($env);
		}

		if ('base64' === $prefix) {
			return base64_decode(strtr($env, '-_', '+/'));
		}

		if ('json' === $prefix) {
			$env = json_decode($env, true);

			if (\JSON_ERROR_NONE !== json_last_error()) {
				throw new RuntimeException(sprintf('Invalid JSON in env var "%s": ', $name) . json_last_error_msg());
			}

			if (null !== $env && !\is_array($env)) {
				throw new RuntimeException(sprintf('Invalid JSON env var "%s": array or null expected, "%s" given.', $name, get_debug_type($env)));
			}

			return $env;
		}

		if ('url' === $prefix) {
			$parsedEnv = parse_url($env);

			if (false === $parsedEnv) {
				throw new RuntimeException(sprintf('Invalid URL in env var "%s".', $name));
			}
			if (!isset($parsedEnv['scheme'], $parsedEnv['host'])) {
				throw new RuntimeException(sprintf('Invalid URL env var "%s": schema and host expected, "%s" given.', $name, $env));
			}
			$parsedEnv += [
				'port' => null,
				'user' => null,
				'pass' => null,
				'path' => null,
				'query' => null,
				'fragment' => null,
			];

			if (null !== $parsedEnv['path']) {
				// remove the '/' separator
				$parsedEnv['path'] = '/' === $parsedEnv['path'] ? null : substr($parsedEnv['path'], 1);
			}

			return $parsedEnv;
		}

		if ('query_string' === $prefix) {
			$queryString = parse_url($env, \PHP_URL_QUERY) ?: $env;
			parse_str($queryString, $result);

			return $result;
		}

		if ('resolve' === $prefix) {
			return preg_replace_callback('/%%|%([^%\s]+)%/', function ($match) use ($name) {
				if (!isset($match[1])) {
					return '%';
				}
				$value = $this->container->getParameter($match[1]);
				if (!is_scalar($value)) {
					throw new RuntimeException(sprintf('Parameter "%s" found when resolving env var "%s" must be scalar, "%s" given.', $match[1], $name, get_debug_type($value)));
				}

				return $value;
			}, $env);
		}

		if ('csv' === $prefix) {
			return str_getcsv($env, ',', '"', \PHP_VERSION_ID >= 70400 ? '' : '\\');
		}

		if ('trim' === $prefix) {
			return trim($env);
		}

		throw new RuntimeException(sprintf('Unsupported env var prefix "%s" for env name "%s".', $prefix, $name));
	}
}