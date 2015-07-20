<?php
namespace Ridibooks\Library\EPub;

class StringUtil
{
	private static $non_printable_ascii = null;
	private static $unicode_non_breaking_space = "\xc2\xa0";
	private static $unicode_zero_width_space = "\xe2\x80\x8b";
	private static $unicode_bom_utf8 = "\xef\xbb\xbf";

	public static function removeUnnecessaryCharacter($string)
	{
		self::initializeNonPrintableAscii();
		$removes = self::$non_printable_ascii;
		$removes[] = self::$unicode_bom_utf8;
		$removes[] = self::$unicode_non_breaking_space;
		$removes[] = self::$unicode_zero_width_space;
		return str_replace($removes, "", $string);
	}

	private static function initializeNonPrintableAscii()
	{
		if (self::$non_printable_ascii === null) {
			$__non_printable_ascii = array(
				0,
				1,
				2,
				3,
				4,
				5,
				6,
				7,
				8,
				9,
				11,
				12,
				14,
				15,
				16,
				17,
				18,
				19,
				20,
				21,
				22,
				23,
				24,
				25,
				26,
				27,
				28,
				29,
				30,
				31,
				127,
			);
			foreach ($__non_printable_ascii as $k => $v) {
				$__non_printable_ascii[$k] = chr($v);
			}
			self::$non_printable_ascii = $__non_printable_ascii;
		}
	}
}
