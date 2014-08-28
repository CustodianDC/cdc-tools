#!/usr/bin/env php
<?php
/*
 * Copyright (C) 2014 CustodianDC Ltd.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

define ('TIMEZONE', 'Europe/London');
define ('MIN_SPEED', 1000000);
define ('MAX_SPEED', 1000000000);
$rrdfilename = NULL;
$datetime = array();
$replace_in = TRUE;
$replace_out = FALSE;
$speed = NULL;
$please = FALSE;
$quiet = FALSE;

function usage_and_exit ($exitcode)
{
	$scriptname = basename ($_SERVER['PHP_SELF']);
	echo "Usage:\n";
	echo "${scriptname} --rrdfile=/path/to/file.rrd --start=YYYY-MM-DD --end=YYYY-MM-DD --speed=SPEED [--direction=<in|out|both>] [--please]\n";
	echo "\t--rrdfile=/path/to/file.rrd     only print details of what would be done, then exit\n";
	echo "\t--start=YYYY-MM-DD              set starting date (" . TIMEZONE . " timezone, 00:00:00)\n";
	echo "\t--end=YYYY-MM-DD                set ending date (" . TIMEZONE . " timezone, 23:59:59)\n";
	echo "\t--direction=<in|out|both>       set direction (default \"in\")\n";
	echo "\t--speed=SPEED                   set cutoff speed in bits per second (k=1000, M=1000k)\n";
	echo "\t--please                        if speed too low or too high\n";
	echo "\t--force                         same as --please\n";
	echo "\t--quiet                         no input/output except error messages and usage\n";
	echo "\t--help                          print this message\n";
	exit ($exitcode);
}

# start with parsing and validating command-line options
$longopt = array
(
	'rrdfile:',
	'start:',
	'end:',
	'direction:',
	'speed:',
	'please',
	'force',
	'quiet',
	'help',
);
if (FALSE === $options = getopt ('', $longopt))
	usage_and_exit (1);
foreach ($options as $optname => $optvalue)
{
	switch ($optname)
	{
		case 'help':
			usage_and_exit (0);
		case 'rrdfile':
			$rrdfilename = $optvalue;
			break;
		case 'start':
		case 'end':
			if (! preg_match ('/^\d\d\d\d-\d\d-\d\d$/', $optvalue))
				usage_and_exit (1);
			try
			{
				$datetime[$optname] = new DateTime ($optvalue);
				$datetime[$optname]->setTimezone (new DateTimeZone (TIMEZONE));
			}
			catch (Exception $e)
			{
				fwrite (STDERR, "Error in --${optname} date value '${optvalue}': " . $e->getMessage() . "\n");
				exit (2);
			}
			break;
		case 'direction':
			switch ($optvalue)
			{
				case 'in':
					$replace_in = TRUE;
					$replace_out = FALSE;
					break;
				case 'out':
					$replace_in = FALSE;
					$replace_out = TRUE;
					break;
				case 'both':
					$replace_in = TRUE;
					$replace_out = TRUE;
					break;
				default:
					usage_and_exit (1);
			}
			break;
		case 'speed':
			if (! preg_match ('/^([1-9][0-9]*)([kM])?$/', $optvalue, $matches))
			{
				fwrite (STDERR, "Error: speed must be an integer number!\n");
				exit (1);
			}
			if (count ($matches) == 2)
				$speed = $matches[1];
			elseif ($matches[2] == 'k')
				$speed = $matches[1] * 1000;
			elseif ($matches[2] == 'M')
				$speed = $matches[1] * 1000000;
			$compress_margin = intval ($speed / 8); # cast to integer to keep output text cleaner
			break;
		case 'please':
		case 'force':
			$please = TRUE;
			break;
		case 'quiet':
			$quiet = TRUE;
			break;
		default:
			usage_and_exit (1);
	}
}
if
(
	! array_key_exists ('start', $datetime) ||
	! array_key_exists ('end', $datetime) ||
	$speed === NULL ||
	$rrdfilename === NULL
)
	usage_and_exit (1);

# enforce the date and time range constraints
$datetime['start']->setTime (0, 0, 0);
$datetime['end']->setTime (23, 59, 59);
$diff = $datetime['start']->diff ($datetime['end']);
if ($diff->invert)
{
	fwrite (STDERR, "Error: the starting date must not be later than the ending date!\n");
	exit (1);
}
$start_ts = $datetime['start']->getTimestamp();
$end_ts = $datetime['end']->getTimestamp();

if (! $quiet)
{
	echo 'Original RRD file      : ' . $rrdfilename . "\n";
	define ('DTFORMAT', 'Y-m-d H:i:s T');
	echo 'Starting date and time : ' . $datetime['start']->format (DTFORMAT) . "\n";
	echo 'Ending date and time   : ' . $datetime['end']->format (DTFORMAT) . "\n";
	echo 'Speed limit            : ' . number_format ($speed) . " bit/s\n";
}

# speed margin sanity check
if ($speed < MIN_SPEED && ! $please)
{
	fwrite (STDERR, "Error: speed below " . number_format (MIN_SPEED) . " bit/s, consider \"--please\" if not a typo\n");
	exit (1);
}
if ($speed > MAX_SPEED && ! $please)
{
	fwrite (STDERR, "Error: speed above " . number_format (MAX_SPEED) . " bit/s, consider \"--please\" if not a typo\n");
	exit (1);
}

# fetch XML representation of the RRD into an array
if (NULL === $xml_text = shell_exec ('rrdtool dump ' . escapeshellarg ($rrdfilename)))
{
	fwrite (STDERR, "Error: failed opening file ${rrdfilename}\n");
	exit (1);
}
$xml_lines = explode ("\n", $xml_text);
unset ($xml_text);
if (! $quiet)
	echo 'Original XML lines     : ' . count ($xml_lines) . "\n";

# scan XML to produce estimates
define ('RE_MATCH', '@<!-- \d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d \S+ / (\d+) --> <row><v>([\d.e+-]+)</v><v>([\d.e+-]+)</v></row>@');
define ('RE_REPLACE', '@<v>([\d.e+-]+)</v><v>([\d.e+-]+)</v>@');

$estimate_in = 0;
$estimate_out = 0;
foreach ($xml_lines as $line)
{
	if (preg_match (RE_MATCH, $line, $matches))
	{
		$timestamp = $matches[1];
		$traffic_in = floatval ($matches[2]);
		$traffic_out = floatval ($matches[3]);
		if ($timestamp >= $start_ts && $timestamp <= $end_ts) # both inclusive
		{
			if ($replace_in && $traffic_in > $compress_margin)
				$estimate_in++;
			if ($replace_out && $traffic_out > $compress_margin)
				$estimate_out++;
		}
	}
}

if (! $quiet)
{
	if ($replace_in)
		echo 'Replacements "in"      : ' . $estimate_in . "\n";
	if ($replace_out)
		echo 'Replacements "out"     : ' . $estimate_out . "\n";
	if ($estimate_in + $estimate_out == 0)
	{
		echo "Conclusion             : nothing to do!\n";
		exit (0);
	}
	# confirm changes
	echo 'Type "yes" to proceed  : '; # no newline
	if ('yes' !== trim (fgets (STDIN))) # strip newline
	{
		echo "Conclusion             : exited on user request\n";
		exit (0);
	}
}

# make a backup copy
$now = new DateTime ('now');
$bakfilename = $rrdfilename . '.backup_' . $now->format ('Y-m-d_H:i:s');
if (file_exists ($bakfilename))
{
	fwrite (STDERR, "Error: backup file '${bakfilename}' already exists!\n");
	exit (1);
}
if (TRUE !== copy ($rrdfilename, $bakfilename))
{
	fwrite (STDERR, "Error: failed to rename '${rrdfilename}' to '${bakfilename}'!\n");
	exit (1);
}
if (! $quiet)
	echo 'Backup RRD file        : ' . $bakfilename . "\n";

# rewrite XML to enforce the maximum
foreach (array_keys ($xml_lines) as $key)
{
	if (preg_match (RE_MATCH, $xml_lines[$key], $matches))
	{
		$timestamp = $matches[1];
		$traffic_in = floatval ($matches[2]);
		$traffic_out = floatval ($matches[3]);
		if ($timestamp >= $start_ts && $timestamp <= $end_ts) # both inclusive
		{
			if ($replace_in && $traffic_in > $compress_margin)
				$xml_lines[$key] = preg_replace (RE_REPLACE, "<v>${compress_margin}</v><v>\\2</v>", $xml_lines[$key]);
			if ($replace_out && $traffic_out > $compress_margin)
				$xml_lines[$key] = preg_replace (RE_REPLACE, "<v>\\1</v><v>${compress_margin}</v>", $xml_lines[$key]);
		}
	}
}

# 'rrdtool restore' cannot handle stdin thus the temporary XML file
if (FALSE === $tmpfilename = tempnam ('.', 'tmpxml.'))
{
	fwrite (STDERR, "Error: tempnam() failed!\n");
	exit (1);
}
if (! $quiet)
	echo 'Temporary XML file     : ' . $tmpfilename . "\n";
if (FALSE === file_put_contents ($tmpfilename, implode ("\n", $xml_lines)))
{
	fwrite (STDERR, "Error: could not write '${tmpfilename}'!\n");
	unlink ($tmpfilename);
	exit (1);
}
exec ('rrdtool restore --force-overwrite ' . escapeshellarg ($tmpfilename) . ' ' . escapeshellarg ($rrdfilename), $output, $retcode);
if ($retcode != 0)
{
	fwrite (STDERR, "Error: failed to execute rrdtool: ${output}\n");
	unlink ($tmpfilename);
	exit (1);
}
unlink ($tmpfilename);
if (! $quiet)
	echo "Conclusion             : work complete!\n";

?>
