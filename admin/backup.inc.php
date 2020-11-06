<?php
/*
*******************************************************
***													***
*** backpack										***
*** Cedric MONTUY pour CHG-WEB                      ***	
*** Original author : Yoshi Sakai					***
***													***
*******************************************************
*/
// Define this to enable debugging
define ('DEBUG', 0);

function PMA_backquote($a_name, $do_it = TRUE){
    if ($do_it
        && PMA_MYSQL_INT_VERSION >= 32306
        && !empty($a_name) && '*' != $a_name) {
        return '`' . $a_name . '`';
    } else {
        return $a_name;
    }
} // end of the 'PMA_backquote()' function

function create_table_sql_string($tablename){
	global $dump_line,$dump_buffer;
    $crlf = "\r\n";

	// Start the SQL string for this table
	$field_header = "CREATE TABLE `$tablename` (";
	$field_string = '';
	
	// Get the field info and output to a string in the correct MySQL syntax
	$result = mysqli_query("DESCRIBE $tablename");
	if (DEBUG) echo "field_info\n\n";
	while ($field_info = mysqli_fetch_array($result)) {
		if (DEBUG) {
			for ($i = 0; $i < count($field_info); $i++) {
				echo "$i: $field_info[$i]\n";
			}
		}
		$field_name = $field_info[0];
		$field_type = $field_info[1];
		$field_not_null = ('YES' == $field_info[2]) ? '' : ' NOT NULL';
		$field_default = (NULL == $field_info[4]) ? '' : sprintf(" default '%s'", $field_info[4]);;
		$field_auto_increment = (NULL == $field_info[5]) ? '' : sprintf(' %s', $field_info[5]);
		$field_string .= $field_string ? ',' : $field_header ;
		$field_string .= $crlf.sprintf('  `%s` %s%s%s%s', $field_name, $field_type, $field_not_null, $field_auto_increment, $field_default);
	}
	// Get the index info and output to a string in the correct MySQL syntax
	$result = mysqli_query("SHOW KEYS FROM $tablename");	//SHOW INDEX FROM 
	if (DEBUG) echo "\nindex_info\n\n";
	while ($row = mysqli_fetch_array($result)) {
        $kname    = $row['Key_name'];
        $ktype  = (isset($row['Index_type'])) ? $row['Index_type'] : '';
        if (!$ktype && (isset($row['Comment']))) $ktype = $row['Comment']; // For Under MySQL v4.0.2
        $sub_part = (isset($row['Sub_part'])) ? $row['Sub_part'] : '';
         if ('PRIMARY' != $kname && 0 == $row['Non_unique']) {
            $kname = 'UNIQUE KEY `'.$kname.'`';
        }
        if ('FULLTEXT' == $ktype) {
            $kname = 'FULLTEXT KEY `'.$kname.'`';
        }
        if (!isset($index[$kname])) {
            $index[$kname] = [];
        }
        if ($sub_part > 1) {
            $index[$kname][] = PMA_backquote($row['Column_name'], 0) . '(' . $sub_part . ')';
        } else {
            $index[$kname][] = PMA_backquote($row['Column_name'], 0);
        }
    } // end while
    mysqli_free_result($result);
    $index_string = '';
    while (list($x, $columns) = @each($index)) {
        $index_string     .= ',' . $crlf;
        if ('PRIMARY' == $x) {
            $index_string .= '   PRIMARY KEY (';
        } else if ('UNIQUE' == substr($x, 0, 6)) {
            $index_string .= '   UNIQUE ' . substr($x, 7) . ' (';
        } else if ('FULLTEXT' == substr($x, 0, 8)) {
            $index_string .= '   FULLTEXT ' . substr($x, 9) . ' (';
        } else {
            $index_string .= '   KEY `' . $x . '` (';
        }
        $index_string .= implode($columns, ', ') . ')';
    } // end while
    $index_string .= $crlf;
	
	// Get the table type and output it to a string in the correct MySQL syntax
	$result = mysqli_query('SHOW TABLE STATUS');
	if (DEBUG) echo "\nstatus_info\n\n";
	while ($status_info = mysql_fetch_array($result)) {
		for ($i = 0; $i < count($status_info); $i++) {
			if (DEBUG) echo "$i: $status_info[$i]\n";

			if ($status_info[0] == $tablename) $table_type = sprintf('TYPE=%s', $status_info[1]);
		}
	}

	// Append the index string to the field string
	$field_string = sprintf('%s%s', $field_string, $index_string);

	// Put the field string in parantheses
	$field_string = sprintf('%s)', $field_string);
	
	// Finalise the MySQL create table string
	$field_string .= $table_type . ';';
	$field_string = "-- \r\n-- ".$tablename." structure.\r\n-- ".$crlf.$field_string.$crlf;
	$dump_buffer .= $field_string;
	preg_match_all("/\r\n/",$field_string,$c);
	$dump_line += count($c[0]);
}
function create_data_sql_string($tablename,$filename,$cfgZipType){
	global $dump_line,$dump_buffer,$query_res;
	// Get field names from MySQL and output to a string in the correct MySQL syntax
	$query_res = mysqli_query("SELECT * FROM $tablename");
	
	// Get table data from MySQL and output to a string in the correct MySQL syntax
	$dump_buffer .= "-- \r\n-- ".$tablename." dump.\r\n-- \r\n";
	$dump_line+=3;
	while ($row = mysqli_fetch_row($query_res)) {
		// Initialise the data string
		$data_string = '';
		// Loop through the records and append data to the string after escaping
		for ($i = 0; $i < mysqli_num_fields($query_res); $i++) {
			if (!isset($row[$i]) || is_null($row[$i]))
				$data_string = sprintf('%s, NULL', $data_string);
			else
				$data_string = sprintf("%s, '%s'", $data_string, mysqli_escape_string($row[$i]));
			//$data_string = str_replace("`","\'",$data_string);
		}
		// Remove the first 2 characters (", ") from the data string
		$data_string = substr($data_string, 2);
		// Put the data string in parantheses and prepend "VALUES "
		$data_string = sprintf('VALUES (%s)', $data_string);
		// Finalise the MySQL insert into string for this record
		$dump_buffer .= sprintf("INSERT INTO `%s` %s;\r\n", $tablename, $data_string);
		$dump_line++;
		check_dump_buffer($filename,$cfgZipType);
	}
}
function make_download($filename,$cfgZipType){
	global $backup_dir,$dump_line,$dump_buffer,$download_count,$download_fname,$mime_type;

	if (('bzip' == $cfgZipType) && function_exists('bzcompress')) {	// (PMA_PHP_INT_VERSION >= 40004 &&
		$filename .= $download_count>0 ? '-' . $download_count . '.sql' : '.sql';
	    $ext       = 'bz2';
	    $mime_type = 'application/x-bzip';
        $op_buffer = bzcompress($dump_buffer);
	} else if (('gzip' == $cfgZipType) && function_exists('gzencode')) {	// (PMA_PHP_INT_VERSION >= 40004 &&
		$filename .= $download_count>0 ? '-' . $download_count . '.sql' : '.sql';
	    $ext       = 'gz';
	    $content_encoding = 'x-gzip';
	    $mime_type = 'application/x-gzip';
        // without the optional parameter level because it bug
	    $op_buffer = gzencode($dump_buffer,9);
	} else if (('zip' == $cfgZipType) && function_exists('gzcompress')) {	// (PMA_PHP_INT_VERSION >= 40000 &&
		$filename .= $download_count>0 ? '-' . $download_count : '';
	    $ext       = 'zip';
	    $mime_type = 'application/x-zip';
        $extbis = '.sql';
        $zipfile = new zipfile();
        $zipfile -> addFile($dump_buffer, $filename . $extbis);
        $op_buffer = $zipfile -> file();
	} else {
	    $ext       = 'sql';
		$cfgZipType = 'none';
	    $mime_type = 'text/plain';
	    $op_buffer = $dump_buffer;
	}
	$fpathname = $backup_dir.$filename.'.'.$ext;
	$fp = fopen($fpathname,'w');
	fwrite($fp, $op_buffer);
	fclose($fp);
	unset($op_buffer);
	if(!file_exists($fpathname)){
		print("Error - $filename does not exist.");
		return ;
	}
	$download_fname[$download_count]['filename'] = $filename.'.'.$ext;
	$download_fname[$download_count]['line'] = $dump_line;
	$download_fname[$download_count]['size'] = filesize($fpathname);
	$download_count++;
}
function purge_allfiles(){
	global $backup_dir;
	if ($handle = opendir( $backup_dir )) {
    while (false !== ($file = readdir($handle))) {
        if (preg_match('/sql/', $file)) {
            //echo "$file\n<BR>";
            unlink($backup_dir.$file);
        }
    }
    closedir($handle);
	}
}
function check_dump_buffer($filename,$cfgZipType){
	global $dump_line,$dump_buffer;
	if ($dump_line >= MAX_DUMPLINE ){//|| strlen($dump_buffer) >= MAX_DUMPSIZE){ 
		make_download($filename,$cfgZipType);
		//unset($GLOBALS['dump_buffer']);
		//unset($GLOBALS['dump_line']);
		$dump_buffer = '';
		$dump_line = 0;
	}
}
function Lock_Tables($tablename_array){
    $q = 'LOCK TABLES';

	for ($i = 0; $i <count($tablename_array); $i++) {
      $q .= ' ' . $tablename_array[$i] . ' read,';
    }
    $q = substr($q,0,strlen($q)-1);
    mysql_query($q);
}
function backup_data($tablename_array, $backup_structure, $backup_data, $filename, $cfgZipType){
	global $time_start,$dump_line,$dump_buffer,$download_count;
	
	$dump_buffer = "-- CHG-WEB.Xoops Backup/Restore Module\r\n-- BackPack\r\n-- https://store.chg-web.com/\r\n";
	$dump_buffer .= "-- --------------------------------------------\r\n";
	preg_match_all("/\r\n/",$dump_buffer,$c);
	$dump_line += count($c[0]);
    mysql_query('FLUSH TABLES');
	Lock_Tables($tablename_array);
	for ($i = 0; $i <count($tablename_array); $i++) {
		if ( $backup_structure ) create_table_sql_string( $tablename_array[$i] );
		if ( $backup_data      ) create_data_sql_string ( $tablename_array[$i], $filename, $cfgZipType);
		check_dump_buffer( $filename , $cfgZipType );
        $time_now = time();
        if ($time_start >= $time_now + 30) {
            $time_start = $time_now;
            header('X-pmaPing: Pong');
        }
	}
    mysqli_query('UNLOCK TABLES');
    if ( $dump_buffer ) make_download( $filename ,$cfgZipType );
}
function restore_data($filename, $restore_structure, $restore_data, $db_selected)
{
	if (!file_exists($filename)) exit();
	$handle = fopen("$filename", 'r');

	$prefix ='';
	while (!feof($handle)) {
//		$buffer = fgets($handle);
		$buffer='';
		while (!feof($handle)) {
			$temp = ["\r\n", "\n", "\r", "\t"];
			$cbuff = str_replace($temp, '', fgets($handle));
			if (!preg_match('`^--`',$cbuff)) $buffer .= $cbuff;
			if (false != preg_match('`;`', $cbuff)) break;
		}
		if (preg_match('/^CREATE TABLE|^INSERT INTO|^DELETE/i', $buffer)){
			if (!$prefix){
				$match = explode(' ', $buffer);
				$prefix = explode('_', $match[2]);
				$prefix = preg_replace('/^`/', '', $prefix[0]);
			}
			$buffer = preg_replace('/' . $prefix . '_/', XOOPS_DB_PREFIX . '_', $buffer);
		}
		if ($buffer) {
			// if this line is a create table query then check if the table already exists
			if (preg_match('`^CREATE TABLE`i',$buffer) ) {
				if ($restore_structure) { 
					$tablename = explode(' ', $buffer);
					$tablename = preg_replace('/`/','',$tablename[2]);
					$result = $xoopsDB->queryF('SHOW TABLES FROM '.$db_selected);
					for ($i = 0; $i < $xoopsDB->getRowsNum($result); $i++) {
						if (mysqli_tablename($result, $i) == $tablename) {
							//$rand = substr(md5(time()), 0, 8);
							//$random_tablename = sprintf("%s_bak_%s", $tablename, $rand);
							mysqli_query("DROP TABLE IF EXISTS $tablename");
							//mysql_query("RENAME TABLE $tablename TO $random_tablename");
							//echo "Backed up $tablename to $random_tablename.<br />\n";
						}
					}
					$result = mysqli_query($buffer);
					if (!$result) {
						echo mysqli_error()."<br />\n";
					} else {
						echo "Table '$tablename' successfully recreated.<br />\n";
					}
				}
			} else {
				if ($restore_data) {
					$result = mysqli_query($buffer);
					if (!$result) echo mysqli_error()."<br />\n";
				}
			}
		}
	}
	fclose($handle);
}
function get_module_tables($dirname)
{
    global $xoopsConfig;
    if (!$dirname ) return;
    $module_handler = xoops_getHandler('module');
    $module = $module_handler->getByDirname($dirname);
    // Get tables used by this module
    $modtables = $module->getInfo('tables');
    if (false != $modtables && is_array($modtables)) {
        return $modtables;
    }
}
function make_module_selection($select_dirname='',$addblank=0)
{
    global $xoopsDB;
	$sql = 'SELECT name,dirname FROM ' . $xoopsDB->prefix('modules');
	if (!$result = $xoopsDB->query($sql)) {
		return false;
	}
	$mod_selections  = "<select name=\"dirname\">\n";
	$mod_selections .= $addblank ? "<option value=''></option>\n" : '';
	while(list($name, $dirname) = $xoopsDB->fetchRow( $result ) ) {
		if (0 == strcmp($dirname, $select_dirname)) $opt = 'selected';
		else $opt= '';
		$mod_selections .= "<option value=\"${dirname}\" ${opt}>${name}</option>\n";
	}
	$mod_selections .= "</select>\n";
	return $mod_selections;
}

/**
 * Maximum upload size as limited by PHP
 * Used with permission from Moodle (http://moodle.org) by Martin Dougiamas
 *
 * this section generates $max_upload_size in bytes
 * @param int $size
 * @return float|int|mixed
 */

function get_real_size($size=0) {
/// Converts numbers like 10M into bytes
    if (!$size) {
        return 0;
    }
    $scan['MB'] = 1048576;
    $scan['Mb'] = 1048576;
    $scan['M'] = 1048576;
    $scan['m'] = 1048576;
    $scan['KB'] = 1024;
    $scan['Kb'] = 1024;
    $scan['K'] = 1024;
    $scan['k'] = 1024;

    while (list($key) = each($scan)) {
        if ((strlen($size)>strlen($key))&&(substr($size, strlen($size) - strlen($key))==$key)) {
            $size = substr($size, 0, strlen($size) - strlen($key)) * $scan[$key];
            break;
        }
    }
    return $size;
}
/**
* Displays the maximum size for an upload
*
* @param int  the size
*
* @return  string   the message
*
* @access  public
*/
function PMA_displayMaximumUploadSize($max_upload_size) {
    [$max_size, $max_unit] = PMA_formatByteDown($max_upload_size);
    return '(' . sprintf(_AM_SELECTAFILE_DESC, $max_size, $max_unit) . ')';
}
/**
 * Formats $value to byte view
 *
 * @param    double   the value to format
 * @param    int  the sensitiveness
 * @param    int  the number of decimals to retain
 *
 * @return   array    the formatted value and its unit
 *
 * @access  public
 *
 * @author   staybyte
 * @version  1.2 - 18 July 2002
 */
function PMA_formatByteDown($value, $limes = 6, $comma = 0)
{
    $dh           = pow(10, $comma);
    $li           = pow(10, $limes);
    $return_value = $value;
    $unit         = $byteunits[0];
	$byteunits = ['Byte', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
	$number_thousands_separator = ',';
	$number_decimal_separator = '.';

    for ( $d = 6, $ex = 15; $d >= 1; $d--, $ex-=3 ) {
        if (isset($byteunits[$d]) && $value >= $li * pow(10, $ex)) {
            $value = round($value / ( pow(1024, $d) / $dh) ) /$dh;
            $unit = $byteunits[$d];
            break 1;
        } // end if
    } // end for

    if ($unit != $byteunits[0]) {
        $return_value = number_format($value, $comma, $number_decimal_separator, $number_thousands_separator);
    } else {
        $return_value = number_format($value, 0, $number_decimal_separator, $number_thousands_separator);
    }

    return [$return_value, $unit];
} // end of the 'PMA_formatByteDown' function
// end function
