<?php namespace OneFile;

/**
 * File Description
 *
 * @author C. Moller <xavier.tnc@gmail.com> - 07 Jun 2014
 *
 * Licensed under the MIT license. Please see LICENSE for more information.
 *
 * @update: C. Moller - 24 December 2016
 *
 * @update: C. Moller - 05 December 2017
 *   - Add Format::nbsp()
 *   - Add/correct function @param definitions
 */

class Format {

	/**
	 *
	 * @param mixed $value
	 * @param array $nullTypes
	 * @param mixed $nullValue
	 * @return mixed
	 */
	public static function nulltype($value = null, $nullTypes = array('', 'NULL'), $nullValue = null)
	{
		return in_array($value, $nullTypes) ? $nullValue : $value;
	}

	/**
	 *
	 * @param string $text
	 * @return string
	 */
	public static function nbsp($text = null)
	{
		return str_replace(' ', '&nbsp;', $text);
	}

	/**
	 *
	 * @param mixed $value
	 * @param mixed $default
	 * @param integer $decimals
	 * @param string $seperator
	 * @return string
	 */
	public static function decimal($value, $default = null, $decimals = 0, $seperator = null)
	{
		if (is_null($value)) return $default;
		return is_numeric($value) ? number_format($value, $decimals, '.', $seperator) : $value;
	}

	/**
	 *
	 * @param mixed $value
	 * @param mixed $default
	 * @param integer $decimals
	 * @param string $symbol
	 * @param string $seperator
	 * @return string
	 */
	public static function currency($value, $default = null, $decimals = 0, $symbol = 'R', $seperator = null)
	{
		if (is_null($value)) return $default;
		return is_numeric($value) ? $symbol . number_format($value, $decimals, '.', $seperator) : $value;
	}

	/**
	 *
	 * @param mixed $value
	 * @param mixed $default
	 * @param integer $decimals
	 * @param string $seperator
	 * @return string
	 */
	public static function percent($value, $default = null, $decimals = 0, $seperator = null)
	{
		if (is_null($value)) return $default;
		return is_numeric($value) ? number_format($value, $decimals, '.', $seperator) . '%' : $value;
	}

	/**
	 *
	 * @param unix|string $value
	 * @param string $format
	 * @return string
	 */
	public static function datetime($value = null, $format = 'Y-m-d H:i:s', $default = null)
	{
		if (empty($value)) return $default;
		return is_numeric($value) ? date($format, $value) : date($format, strtotime($value));
	}

	/**
	 *
	 * @param unix|string $value
	 * @param string $format
	 * @return string
	 */
	public static function date($value = null, $format = 'Y-m-d', $default = null)
	{
		return self::datetime($value, $format, $default);
	}

	/**
	 *
	 * @param boolean $bool_value
	 * @return string
	 */
	public static function yesNo($bool_value)
	{
		return ($bool_value) ? 'Yes' : 'No';
	}

	/**
	 *
	 * @param boolean $bool_value
	 * @return string
	 */
	public static function trueFalse($bool_value)
	{
		return ($bool_value) ? 'true' : 'false';
	}

	/**
	 * Limit the number of characters in a string.
	 *
	 * @param  string  $value
	 * @param  int     $limit
	 * @param  string  $end
	 * @return string
	 */
	public static function limit($value, $limit = 100, $end = '...')
	{
		if (mb_strlen($value) <= $limit) return $value;

		return rtrim(mb_substr($value, 0, $limit, 'UTF-8')).$end;
	}

	/**
	 * Limit the number of words in a string.
	 *
	 * @param  string  $value
	 * @param  int     $words
	 * @param  string  $end
	 * @return string
	 */
	public static function words($value, $words = 100, $end = '...')
	{
		$matches = array();

		preg_match('/^\s*+(?:\S++\s*+){1,'.$words.'}/u', $value, $matches);

		if ( ! isset($matches[0])) return $value;

		if (strlen($value) == strlen($matches[0])) return $value;

		return rtrim($matches[0]).$end;
	}

	/**
	 * Returns the first word/part of a string before the delimiter char.
	 *
	 * @param  string  $value
	 * @param  string  $delimiter
	 * @return string
	 */
	public static function firstword($value, $delimiter = ' ')
	{
		return strtok($value, $delimiter);
	}

	/**
	 * Convert the given string to title case.
	 *
	 * @param  string  $value
	 * @return string
	 */
	public static function title($value)
	{
		return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
	}

	/**
	 * Convert a string to snake case.
	 *
	 * @param  string  $value
	 * @param  string  $delimiter
	 * @return string
	 */
	public static function snake($value, $delimiter = '_')
	{
		$replace = '$1'.$delimiter.'$2';

		return ctype_lower($value) ? $value : strtolower(preg_replace('/(.)([A-Z])/', $replace, $value));
	}

	/**
	 * Convert a value to studly caps case.
	 *
	 * @param  string  $value
	 * @return string
	 */
	public static function studly($value, $delimiter = '')
	{
		$value = ucwords(str_replace(array('-', '_'), ' ', $value));

		return ($delimiter == ' ') ? $value : str_replace(' ', $delimiter, $value);
	}

	/**
	 * Convert a value to camel case.
	 *
	 * @param  string  $value
	 * @return string
	 */
	public static function camel($value)
	{
		return lcfirst(static::studly($value));
	}

	/**
	 * Generate a URL friendly "slug" from a given string.
	 *
	 * @param  string  $title
	 * @param  string  $separator
	 * @return string
	 */
	public static function slug($title, $separator = '-')
	{

		$flip = ($separator == '-') ? '_' : '-';

		$patterns = array(
			'/['.preg_quote($flip).']+/u',					// Convert all dashes/underscores into separator
			'/[^'.preg_quote($separator).'\pL\pN\s]+/u',	// Remove all characters that are not the separator, letters, numbers, or whitespace.
			'/['.preg_quote($separator).'\s]+/u'			// Replace all separator characters and whitespace by a single separator
		);

		$replacements = array($flip, '', $separator);

		foreach ($patterns as $i => $pattern)
		{
			$title = preg_replace($pattern, $replacements[$i], $title);
		}

		return trim($title, $separator);
	}

	public static function htmlEntities($text)
	{
		$text = htmlentities($text, ENT_QUOTES | ENT_IGNORE, 'UTF-8');
		$text = str_replace('  ', '&nbsp;&nbsp;', $text);
		return $text;
	}

	public static function nl2br($text)
	{
		return str_replace(["\r", "\n"], ['', '<br>'], $text);
	}

	/**
	 * E.g.  203948123 Bytes => "???.?? MB"
	 *
	 * @param integer $size in Bytes
	 * @return type
	 */
	public static function filesize($size = null)
	{
		if ($size < 1024)
			return $size . ' B';
		elseif ($size < 1048576)
			return round($size / 1024, 2) . ' KB';
		elseif ($size < 1073741824)
			return round($size / 1048576, 2) . ' MB';
		elseif ($size < 1099511627776)
			return round($size / 1073741824, 2) . ' GB';
		else
			return round($size / 1099511627776, 2) . ' TB';
	}

}
