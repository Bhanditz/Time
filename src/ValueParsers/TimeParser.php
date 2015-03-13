<?php

namespace ValueParsers;

use DataValues\IllegalValueException;
use DataValues\TimeValue;
use InvalidArgumentException;

/**
 * ValueParser that parses the string representation of a time.
 *
 * @since 0.2
 *
 * @licence GNU GPL v2+
 * @author Adam Shorland
 */
class TimeParser extends StringValueParser {

	const FORMAT_NAME = 'time';

	/**
	 * @since 0.3
	 */
	const OPT_PRECISION = 'precision';
	const OPT_CALENDAR = 'calendar';

	/**
	 * @since 0.3
	 */
	const CALENDAR_GREGORIAN = 'http://www.wikidata.org/entity/Q1985727';
	const CALENDAR_JULIAN = 'http://www.wikidata.org/entity/Q1985786';
	const PRECISION_NONE = 'noprecision';

	/**
	 * @var CalendarModelParser
	 */
	private $calendarModelParser;

	/**
	 * @since 0.1
	 *
	 * @param CalendarModelParser|null $calendarModelParser
	 * @param ParserOptions|null $options
	 */
	public function __construct( CalendarModelParser $calendarModelParser = null, ParserOptions $options = null ) {
		parent::__construct( $options );

		$this->defaultOption( TimeParser::OPT_CALENDAR, TimeParser::CALENDAR_GREGORIAN );
		$this->defaultOption( TimeParser::OPT_PRECISION, TimeParser::PRECISION_NONE );

		$this->calendarModelParser = $calendarModelParser ?: new CalendarModelParser();
	}

	protected function stringParse( $value ) {
		if ( !is_string( $value ) ) {
			throw new InvalidArgumentException( '$value must be a string' );
		}

		$timeParts = $this->splitTimeString( $value );
		$timeParts['year'] = $this->padYear( $timeParts['year'] );

		$calendarOpt = $this->getOption( TimeParser::OPT_CALENDAR );
		$calendarModelRegex = '/(' . preg_quote( self::CALENDAR_GREGORIAN, '/' ). '|' . preg_quote( self::CALENDAR_JULIAN, '/' ) . ')/i';

		if( $timeParts['calendar'] === '' && preg_match( $calendarModelRegex, $calendarOpt ) ) {
			$timeParts['calendar'] = $calendarOpt;
		} else if( $timeParts['calendar'] !== '' ) {
			$timeParts['calendar'] = $this->calendarModelParser->parse( $timeParts['calendar'] );
		} else {
			$timeParts['calendar'] = self::CALENDAR_GREGORIAN;
		}

		$precisionOpt = $this->getOption( TimeParser::OPT_PRECISION );
		$precisionFromTime = $this->getPrecisionFromTimeParts( $timeParts );
		if( is_int( $precisionOpt ) && $precisionOpt <= $precisionFromTime ) {
			$precision = $precisionOpt;
		} else {
			$precision = $precisionFromTime;
		}

		$time = $this->getTimeStringFromParts( $timeParts );
		try {
			return new TimeValue( $time, 0, 0, 0, $precision, $timeParts['calendar'] );
		} catch ( IllegalValueException $ex ) {
			throw new ParseException( $ex->getMessage(), $value, self::FORMAT_NAME );
		}
	}

	/**
	 * Pads the given year to force year to have 16 digits
	 * @param string $year in a format such as 0002013
	 * @return string
	 */
	private function padYear( $year ) {
		return str_pad( $year, 16, '0', STR_PAD_LEFT );
	}

	/**
	 * @param array $timeParts with the following keys.
	 *            sign, year, month, day, hour, minute, second, calendar
	 *
	 * @return int precision as a TimeValue PRECISION_ constant
	 */
	private function getPrecisionFromTimeParts( $timeParts ) {
		if ( $timeParts['second'] !== '00' ) {
			$precision = TimeValue::PRECISION_SECOND;
		} elseif ( $timeParts['minute'] !== '00' ) {
			$precision = TimeValue::PRECISION_MINUTE;
		} elseif ( $timeParts['hour'] !== '00' ) {
			$precision = TimeValue::PRECISION_HOUR;
		} elseif ( $timeParts['day'] !== '00' ) {
			$precision = TimeValue::PRECISION_DAY;
		} elseif ( $timeParts['month'] !== '00' ) {
			$precision = TimeValue::PRECISION_MONTH;
		} else {
			$precision = $this->getPrecisionFromYear( $timeParts['year'] );
		}

		return $precision;
	}

	/**
	 * @param string $year
	 * @return int precision
	 */
	private function getPrecisionFromYear( $year ) {
		// default to year precision for range 4000 BC to 4000
		if ( $year >= -4000 && $year <= 4000 ) {
			return TimeValue::PRECISION_YEAR;
		}

		$rightZeros = strlen( $year ) - strlen( rtrim( $year, '0' ) );
		$precision = TimeValue::PRECISION_YEAR - $rightZeros;
		if( $precision < TimeValue::PRECISION_Ga ) {
			$precision = TimeValue::PRECISION_Ga;
		}

		return $precision;
	}

	/**
	 * @param string $value
	 *
	 * @throws ParseException
	 * @return string[] Array with the following keys.
	 *            sign, year, month, day, hour, minute, second, calendar
	 */
	private function splitTimeString( $value ) {
		$pattern = '@^'
			. '\s*' . '([\+\-]?)'
			. '\s*' . '(\d{1,16})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z'
			. '\s*\(?\s*' . CalendarModelParser::MODEL_PATTERN . '\s*\)?'
			. '\s*$@iu';

		if ( !preg_match( $pattern, $value, $groups ) ) {
			throw new ParseException( 'Malformed time', $value, self::FORMAT_NAME );
		}

		return array(
			'sign' => $groups[1],
			'year' => $groups[2],
			'month' => $groups[3],
			'day' => $groups[4],
			'hour' => $groups[5],
			'minute' => $groups[6],
			'second' => $groups[7],
			'calendar' => $groups[8],
		);
	}

	/**
	 * @param array $timeParts with the following keys.
	 *            sign, year, month, day, hour, minute, second, calendar
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return string
	 */
	private function getTimeStringFromParts( array $timeParts ) {
		if( array_keys( $timeParts ) !== array( 'sign', 'year', 'month', 'day', 'hour', 'minute', 'second', 'calendar' ) ) {
			throw new InvalidArgumentException( 'Time string can not be created with missing $timeParts keys' );
		}
		return $timeParts['sign']
			. $timeParts['year'] . '-'
			. $timeParts['month'] . '-'
			. $timeParts['day'] . 'T'
			. $timeParts['hour'] . ':'
			. $timeParts['minute'] . ':'
			. $timeParts['second'] . 'Z';

	}

}
