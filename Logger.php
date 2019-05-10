<?php namespace OneFile;

/**
 * @author C. Moller <xavier.tnc@gmail.com> -2012
 * @update C. Moller 7 June 2014 - Complete Rewrite
 * 				- Changed from fully static to regular class with magic methods.
 * 				- Significantly simplified
 */
class Logger
{

	/**
	 *
	 * @var boolean
	 */
	protected $enabled = false;

	/**
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * Gets set in the constructor or by calling setFilename('optional/relative/sub/path/newname.log')
	 * without a second argument to specify the type.
	 *
	 * Note: The default filename/filepath must always be relative to the Logs Base Path specified in
	 * the constructor.
	 *
	 * @var string
	 */
	protected $defaultFilename;

	/**
	 * Can be string, function or closure. We use default generator if not defined
	 *
	 * @var array()
	 */
	protected $filenames = array();

	/**
	 * Stores the compiled filenames if we use closures or sprintf() strings as filenames
	 *
	 * @var array
	 */
	protected $compiledFilenames;

	/**
	 * Indicates which log types are allowed.
	 *
	 * NB: If $allowedTypes = array(), all log types will pass!
	 *
	 * @var array
	 */
	protected $allowedTypes = array();

	/**
	 *
	 * @var string
	 */
	protected $shortDateFormat = 'Y-m-d';

	/**
	 *
	 * @var string
	 */
	protected $longDateFormat = 'd M Y H:i:s';

	/**
	 * Can be sprintf() string, function or closure
	 *
	 * @var mixed
	 */
	protected $formatter;

	/**
	 * The log file permissions to apply
	 *
	 * @var octal
	 */
	protected $mode = 0775;

	/**
	 *
	 * @param mixed $basePath
	 * @param array|string $allowedTypes e.g. array('error', 'info', 'debug')  or 'error|info|debug'
	 * @param boolean $enabled
	 */
	public function __construct($basePath = null, $allowedTypes = null, $enabled = true)
	{
		$this->setBasePath($basePath);

		$this->setAllowedTypes($allowedTypes);

		$this->enabled = $enabled;

		$this->defaultFilename = $this->getDate() . '.log';
	}

	/**
	 *
	 * @return string
	 */
	public function getLogPath()
	{
		return $this->path;
	}

	/**
	 *
	 * @return array
	 */
	public function getFilenames()
	{
		return $this->filenames;
	}

	/**
	 * Get filename based on the content type of $this->filenames
	 *  - Content can be an array or single: closure, callable, sprintf() string
	 *  - If the content is an array, the Log $type value is used as key.
	 *
	 * Closures and sprintf() strings get compiled with: logType, shortDate and longDate as possible parameters
	 *
	 * Compiled filenames get cached in the $this->compiled array to avoid re-compiling on every request
	 *
	 * @return string
	 */
	public function getFilename($type = null)
	{

		//We transfer $type to $key here to allow setting a default for $key while still being able
		//to pass the original $type value to any custom filename function specified.
		if ($type)
		{
			$key = $type;
		}
		else
		{
			$key = 'info';
		}

		if($this->compiledFilenames and isset($this->compiledFilenames[$key]))
		{
			return $this->compiledFilenames[$key];
		}

		if (isset($this->filenames[$key]))
		{
			$filename_uncompiled = $this->filenames[$key];
		}
		else
		{
			//Return the default filename if we can't find a configured filename for the specified type of log.
			return $this->defaultFilename;
		}

		if (is_callable($filename_uncompiled))
		{
			$filename = $filename_uncompiled($type, $this);

			$this->compiledFilenames[$key] = $filename;

			return $filename;
		}

		//Use indexed references inside your filename template if the parameters aren't in
		//the correct sequence.   %1$s = type, %2$s = short, %3$s = long.
		$filename = sprintf($filename_uncompiled, $type, $this->getDate(), $this->getDate(true));

		$this->compiledFilenames[$key] = $filename;

		return $filename;

	}

	/**
	 * Log message formatter.
	 * Override if you don't like the default implementation
	 *
	 * @param string $message
	 * @param string $type
	 * @return string
	 */
	public function formatMessage($message, $type = 'info')
	{
		if ($this->formatter)
		{
			if (is_callable($this->formatter))
			{
				return $this->formatter($message, $type, $this);
			}
			else
			{
				//Use indexed references inside your message template if the parameters aren't in
				//the correct sequence.   %1$s = message, %2$s = type, %3$s = short, %4$s = long.
				return sprintf($this->formatter, $message, $type, $this->getDate(), $this->getDate(true));
			}
		}
		else
		{
			return '[' . str_pad(ucfirst($type), 5) . "]:\t" . $this->getDate(true) . ' - ' . $message . PHP_EOL;
		}
	}

	/**
	 * No error checking or supressing errors. Keep it fast.
	 * Make sure your folders exist and permissions are set correctly or expect fatal errors!
	 *
	 * @param string $message
	 * @param string $type
	 * @param string $filename
	 */
	public function write($message = '', $type = null, $filename = null)
	{
		if ( ! $this->enabled) return;

		if ($type)
		{
			$type = strtolower($type);

			if ($this->allowedTypes and !in_array($type, $this->allowedTypes)) return;

			$message = $this->formatMessage($message, $type);
		}

		if ( ! $filename)
		{
			$filename = $this->getFilename($type);
		}

		$path = $this->path;

		$groupPath = dirname($filename);

		if ($groupPath)
		{
			$path .= '/' . $groupPath;
		}

		if ( ! is_dir($path))
		{
			$oldumask = umask(0);

			mkdir($path, $this->mode, true);

			umask($oldumask);
		}

		$logFilePath = $this->path ? $this->path . '/' . $filename : $filename;

		file_put_contents($logFilePath, $message, FILE_APPEND | LOCK_EX);

		//chmod($logFilePath, $this->mode);
	}

	/**
	 *
	 * @param string $shortDateFormat
	 * @return \OneFile\Log
	 */
	public function setShortDateFormat($shortDateFormat)
	{
		$this->shortDateFormat = $shortDateFormat;

		return $this;
	}

	/**
	 *
	 * @param string $longDateFormat
	 * @return \OneFile\Log
	 */
	public function setLongDateFormat($longDateFormat)
	{
		$this->longDateFormat = $longDateFormat;

		return $this;
	}

	/**
	 *
	 * @param string $logPath
	 * @return \OneFile\Log
	 */
	public function setBasePath($logPath)
	{
		$this->path = $logPath;

		return $this;
	}

	/**
	 * Set the default filename or specify a filename for logs
	 * of type $logFile.
	 *
	 * If $logFile is an array of type names,
	 * the same filename will be used for each of the types specified in the array.
	 *
	 * @param string $filename
	 * @param array|string $logType
	 * @return \OneFile\Log
	 */
	public function setFilename($filename, $logType = null)
	{
		if ( ! $logType)
		{
			$this->defaultFilename = $filename;
		}
		elseif (is_array($logType))
		{
			foreach($logType as $type)
			{
				$this->filenames[strtolower($type)] = $filename;
			}
		}
		else
		{
			$this->filenames[strtolower($logType)] = $filename;
		}

		return $this;
	}

	/**
	 * Like setFilename() but $logType is required!
	 *
	 * @param string $filename
	 * @param array|string $logType
	 * @return \OneFile\Log
	 */
	public function addFilename($filename, $logType)
	{
		return $this->setFilename($filename, $logType);
	}

	/**
	 *
	 * @param array|string $types e.g. array('error', 'info', 'debug')  or 'error|info|debug'
	 * @param boolean $replaceExisting
	 * @return \OneFile\Log
	 */
	public function addAllowedTypes($types, $replaceExisting = false)
	{
		if ( ! $types)
		{
			$this->allowedTypes = array();
			return $this;
		}

		if ($types and ! is_array($types))
		{
			$types = explode('|', $types);
		}

		if ($replaceExisting)
		{
			$this->allowedTypes = $types;
		}
		else
		{
			$this->allowedTypes = array_merge($this->allowedTypes, $types);
		}

		// Always save types in lower case.  i.e. Types aren't case sensitive
		foreach ($this->allowedTypes as $key => $type)
		{
			$this->allowedTypes[$key] = strtolower($type);
		}

		return $this;
	}

	/**
	 * Same as addAllowedTypes but we don't retain any existing types.
	 *
	 * @param array|string $types e.g. array('error', 'info', 'debug')  or 'error|info|debug'
	 */
	public function setAllowedTypes($types)
	{
		$this->addAllowedTypes($types, true);
	}

	/**
	 * Could be a closure, callable or sprintf() format string.
	 *
	 * @param mixed $formatter
	 * @return \OneFile\Log
	 */
	public function setLineFormatter($formatter)
	{
		$this->formatter = $formatter;

		return $this;
	}

	/**
	 *
	 * @param octal $mode
	 * @return \OneFile\Log
	 */
	public function setFileMode($mode)
	{
		$this->mode = $mode;

		return $this;
	}

	/**
	 *
	 * @return \OneFile\Log
	 */
	public function enable()
	{
		$this->enabled = true;

		return $this;
	}

	/**
	 *
	 * @return \OneFile\Log
	 */
	public function disable()
	{
		$this->enabled = false;

		return $this;
	}

	/**
	 * Get or Set the Log Enabled state
	 * No Parameter == GET
	 *
	 * @return boolean
	 */
	public function enabled($value = null)
	{
		if (is_null($value))
		{
			return $this->enabled;
		}
		else
		{
			$this->enabled = $value;
		}
	}

	/**
	 *
	 * @param boolean $long
	 * @return string
	 */
	public function getDate($long = false)
	{
		return $long ? date($this->longDateFormat) : date($this->shortDateFormat);
	}

	/**
	 *
	 * @param type $name
	 * @param type $arguments
	 */
	public function __call($name, $arguments)
	{
		if ( ! $this->enabled) return;

		switch(count($arguments))
		{
			case 1:
				$this->write($arguments[0], $name); // e.g. $log->debug('message');
				break;

			case 2:
				$this->write($arguments[1], $name, $arguments[0]); // e.g $log->mylogtype('/path/to/logfile.log', 'message')
				break;

			default:
				$this->write('', $name);
		}
	}

}
