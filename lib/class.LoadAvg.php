<?php
/**
* LoadAvg - Server Monitoring & Analytics
* http://www.loadavg.com
*
* Main controller class for LoadAvg 2.0
*
* @link https://github.com/loadavg/loadavg
* @author Karsten Becker
* @copyright 2014 Sputnik7
*
* This file is licensed under the Affero General Public License version 3 or
* later.
*/

class LoadAvg
{
	public static $_settings; // storing standard settings and/or loaded modules settings
	public static $_classes; // storing loaded modules classes
	public static $_modules; // storing loaded modules
	public static $current_date; // current date
	private static $_timezones; // Cache of timezones

	// Periodas
	public static $period;
	public static $period_minDate;
	public static $period_maxDate;
	public static $settings_ini;
	public $timeStart = null, $timeFinish = null;


	/**
	 * setSettings
	 *
	 * Stores the standard settings
	 *
	 * @param string $module name of the module
	 * @param array $args array of module settings
	 */
	public function setSettings($module, $args)
	{
		@self::$_settings->$module = $args;
	}

	/**
	 * __construct
	 *
	 * Class constructor
	 *
	 */

	public function __construct()
	{

		date_default_timezone_set("UTC");
		self::$settings_ini = "settings.ini.php";

		$this->setSettings('general',
			parse_ini_file(APP_PATH . '/config/' . self::$settings_ini, true)
		);

		date_default_timezone_set(self::$_settings->general['timezone']);

		self::$current_date = (isset($_GET['logdate']) && !empty($_GET['logdate'])) ? $_GET['logdate'] : date("Y-m-d");

		//loads modules code
		foreach ( self::$_settings->general['modules'] as $key => &$value ) {
			if ( $value == "true" ) {
				try {
					require_once $key . DIRECTORY_SEPARATOR . 'class.' . $key . '.php';
					self::$_classes[$key] = new $key;
				} catch (Exception $e) {
					throw Exception( $e->getMessage() );
				}
			}
		}

		if (is_dir(HOME_PATH . '/lib/modules/')) {

			foreach (glob(HOME_PATH . "/lib/modules/*/class.*.php") as $filename) {
				$filename = explode(".", basename($filename));
				self::$_modules[$filename[1]] = strtolower($filename[1]);
			}
		}

	}

	/**
	 * is_dir_empty
	 *
	 * Checks if specified directory is empty or not.
	 *
	 * @param string $dir path to directory
	 */

	private function is_dir_empty($dir) {
		if (!is_readable($dir)) return NULL;
		return (count(scandir($dir)) == 2);
	}

	/**
	 * createFirstLogs
	 *
	 * Creates first log files for every loaded modules 
	 * only run once - after installation - and may not be needed as modules will do this themselves
	 * if the file isnt there they create it
	 *
	 */

	public function createFirstLogs()
	{

		//only does it if DIR is empty ?
		if ( $this->is_dir_empty(HOME_PATH . '/' . self::$_settings->general['logs_dir']) ) {

			$loaded = self::$_settings->general['modules'];
			$logdir = HOME_PATH . '/' . self::$_settings->general['logs_dir'];

			$test_nested = false;

			// Check for each module we have loaded
			foreach ( $loaded as $module => $value ) {
				if ( $value == "false" ) continue;

				$moduleSettings = self::$_settings->$module;

				// Check if loaded module needs loggable capabilities
				if ( $moduleSettings['module']['logable'] == "true" ) {
					foreach ( $moduleSettings['logging']['args'] as $args) {

						$args = json_decode($args);
						$class = self::$_classes[$module];
						
						$caller = $args->function;

						//skip network interfaces as they have nested logs and work differently
						//later need to skip all other nested logs as we check those below
						
						if ( $args->logfile == "network_%s_%s.log" )
						{
							$test_nested = true;
						}
						else
						{
							$caller = sprintf($args->function, sprintf("'". $args->logfile . "'", date('Y-m-d')));
							$caller = $args->function;
							
							//dont work for network ?
							$class->logfile = $logdir . sprintf($args->logfile, date('Y-m-d'));
							$class->logfile = $logdir . $args->logfile;

							$class->$caller();	
						}
					}
				}

				//network interface is off at install time so dont really matter at this point

				if ($test_nested == true) {

					//now do nested charts 
					foreach (LoadAvg::$_settings->general['network_interface'] as $interface => $value) {
																					
								$caller = sprintf($args->function, sprintf("'". $args->logfile . "'", date('Y-m-d') , $interface  ));
								$caller = $args->function;
								
								//dont work for network ?
								$class->logfile = $logdir . sprintf($args->logfile, date('Y-m-d') , $interface );
								$class->logfile = $logdir . $args->logfile;

								$class->$caller();	
					}
				}
			}
		}
	}

/*
 * used when we turn modules on and off
 * this needs to only build the log file for modules that have no log file in /logs
 * also be great to pass the module over if we know 
 * what module has changed or been enabled
 */

	public static function rebuildLogs()
	{

			echo "Rebuild Logs  \n";

			$loaded = self::$_settings->general['modules'];

			$logdir = HOME_PATH . '/' . self::$_settings->general['logs_dir'];

			// Check for each module we have loaded
			foreach ( $loaded as $module => $value ) {
				if ( $value == "false" ) continue;

				$moduleSettings = self::$_settings->$module;

				// Check if loaded module needs loggable capabilities
				if ( $moduleSettings['module']['logable'] == "true" ) {
					foreach ( $moduleSettings['logging']['args'] as $args) {
						$args = json_decode($args);
						$class = self::$_classes[$module];

						$caller = $args->function;

						$class->logfile = $logdir . $args->logfile;

						//what does this do ? run args function ?
						$class->$caller();
					}
				}
			}

	}

	/**
	 * genChart - move from modules into core ASAP
	 *
	 * Function witch passes the data formatted for the chart view
	 *
	 * @param array @moduleSettings settings of the module
	 * @param string @logdir path to logfiles folder
	 *
	 */
	
	/*
	public function genChart($moduleSettings, $logdir)
	{

		$charts = $moduleSettings['chart'];
		$module = __CLASS__;
		$i = 0;
		foreach ( $charts['args'] as $chart ) {
			$chart = json_decode($chart);

			//grab the log file and date
			$this->logfile = $logdir . sprintf($chart->logfile, self::$current_date);
			
			if ( file_exists( $this->logfile )) {
				$i++;				

				$caller = $chart->function;
				$stuff = $this->$caller( (isset($moduleSettings['module']['url_args']) && isset($_GET[$moduleSettings['module']['url_args']])) ? $_GET[$moduleSettings['module']['url_args']] : '2' );
				$logfileStatus = false;
				
			} else {
				$logfileStatus = true;
			}
			
			include APP_PATH . '/views/chart.php';
		}
	}
	*/

/*
 * used to test if log files are being created by logger
 * needs better testing currently a bit of a hack
 * as we just test if the log directory is empty or not
 */

 	function testLogs( $mode = true)
	{

			$loaded = self::$_settings->general['modules'];
			$logdir = HOME_PATH . '/' . self::$_settings->general['logs_dir'];

			$test_worked = false;
			$test_nested = false;

			if ( $this->is_dir_empty($logdir))
				return false;

			// Check for each module we have loaded
			foreach ( $loaded as $module => $value ) {
				if ( $value == "false" ) continue;

				$moduleSettings = self::$_settings->$module;

				// Check if loaded module needs loggable capabilities
				if ( $moduleSettings['module']['logable'] == "true" ) {
					
					foreach ( $moduleSettings['logging']['args'] as $args) {
						
						$args = json_decode($args);
						$class = self::$_classes[$module];
						
						$caller = $args->function;

						//skip network interfaces as they have nested logs and work differently
						//later need to skip all nested logs as we check those below
						
						if ( $args->logfile == "network_%s_%s.log" )
						{
							$test_nested = true;
						}
						else
						{
							$filename = ( $logdir . sprintf($args->logfile, date('Y-m-d')) );

							if (file_exists($filename)) {
						    	$test_worked = true;
							}

							if ($mode == true)
								echo "Log: $filename Status: $test_worked  \n";		
						}
					}
				}
			}

			if ($test_nested == true) {

				//now do nested charts 
				foreach (LoadAvg::$_settings->general['network_interface'] as $interface => $value) {

					if (  !( isset(LoadAvg::$_settings->general['network_interface'][$interface]) 
						&& LoadAvg::$_settings->general['network_interface'][$interface] == "true" ) )
						continue;

					$filename = ( $logdir . sprintf($args->logfile, date('Y-m-d') , $interface ) );
																				
					if (file_exists($filename)) {
				    	$test_worked = true;
					}

					if ($mode == true)
						echo "Log: $filename Status: $test_worked  \n";
				}
			}

			return $test_worked;
	}


	


	/**
	 * checkWritePermissions
	 *
	 * Checks if specified file has write permissions.
	 *
	 * @param string $file path to file
	 */

	public function checkWritePermissions( $file )
	{
		if ( is_writable( $file ) )
			return true;
		else
			return false;
	}

	/**
	 * checkInstaller
	 *
	 * Checks if installer is removed or not
	 *
	 */

	public function checkInstaller() {


		$install_loc = HOME_PATH . "/install/index.php";

		if ( file_exists($install_loc) )
			return false;
		else
			return true;
	}

	/**
	 * checkInstall
	 *
	 * Checks if is still installation progress and redirects if TRUE.
	 *
	 */

	public function checkInstall() {

		$install_loc = HOME_PATH . "/install/index.php";

		if ( file_exists($install_loc) ) {
			ob_end_clean();
            header("Location: ../install/index.php");
		}
	}


	/**
	 * cleanUpInstaller
	 *
	 * Checks if is still installation progress and redirects if TRUE.
	 *
	 */

	public function cleanUpInstaller() {

		//location of core settings
		$settings_file = APP_PATH . '/config/settings.ini.php';

		//see if we can write to settings file
		if ( $this->checkWritePermissions( $settings_file ) ) 
		{
			/* 
			 * Create first log files for all active modules 
			 * only executes if there are no log files
	 		 */		
			$this->createFirstLogs();

			/* 
			 * clean up installation files
	 		 */	

			//if installer is not present (true) leave
			if ( $this->checkInstaller() ) {
				header("Location: index.php");
			} 
			else 
			{
				//clean up - try to delete installer if we have permissions
				$installer_file = HOME_PATH . "/install/index.php";
				$installer_loc = HOME_PATH . "/install/";

				unlink($installer_file);
				rmdir($installer_loc);

				//check again if it worked exit
				if ( $this->checkInstaller() ) {
					header("Location: index.php");
				}
				else
				{ 
					//if not throw a error and exit
					require_once APP_PATH . '/layout/secure.php'; 
					require_once APP_PATH . '/layout/footer.php'; 
					
					exit;
				}
			}
		} else {
			header("Location: /install/index.php?step=1");
		}
	}


	/**
	 * getUIcookie
	 *
	 * used to get status of accordions - collapsed or visable from the loadUI cookie
	 * using code to manage accordion state is in common.js
	 *
	 */

	public function getUIcookie ( &$data1,  &$data2, $module) 
	{

		$myCookie = $_COOKIE["loadUIcookie"];
		$cookie = stripslashes($myCookie);

		$savedCardArray = json_decode($cookie, true);

		//these are the values we need to grab
		$data1 = "accordion-body collapse in";
		$data2 = "true";

		foreach ($savedCardArray as &$value) {

			$myval = explode("=", $value);

			if ($module == $myval[0]) {

				if ($myval[1] == "true") {
					$data1 = "accordion-body collapse in";
				    $data2 = "true";
				}
				else {
					$data1 = "accordion-body collapse";
				    $data2 = "false";
				   }
			}

		}



	}

	/**
	 * getProcStats
	 *
	 * parses /proc/stat and returns line $theLine
	 * move this out into functions really
	 *
	 */

	public function getProcStats (array &$data, $theLine = 0) 
	{

        //we grab data from proc/stat in one pass as it changes as you read it
  		$stats = file('/proc/stat'); 

  		//if array is emoty we didnt work
		if($stats === array())
        	return false;

        //echo 'STATS:' . $stats[1];

        //grab cpu data
		$data = explode(" ", preg_replace("!cpu +!", "", $stats[$theLine])); 

       return true; 

	}


	/**
	 * checkRedline
	 *
	 * checks for redline in array in charting modules
	 * move this out into functions really
	 *
	 */


	function checkRedline (array &$data) 
	{

		// clean data for missing values
		$redline = ($data[1] == "-1" ? true : false);

		if ($redline) {
			$data[1]=0.0;
			$data[2]=0.0;
			$data[3]=0.0;
		}

		//echo '<pre>'; print_r ($data); echo '</pre>';

		return $redline;

	}

	/**
	 * getDateRange
	 *
	 * gets the current data or date range and returns to module
	 * will return date range or current date if no range
	 *
	 */

	public function getDateRange ()
	{

		$currentDate = self::$current_date;

		//dates of all the log files in logs folder
		$dates = self::getDates();

		$dateArray = array();

		//$period is set when we are pulling a range of data
		if ( LoadAvg::$period ) {

			//here we check that we have log files inside the range 
			$loop = 0;
			foreach ( $dates as $date ) {
				if ( $date >= self::$period_minDate && $date <= self::$period_maxDate ) 
				{
					$dateArray[$loop] = $date;
					$loop++;
				}
			}
		} 
		else
		{
			$dateArray[0] = $currentDate;
		}

		return $dateArray;
	}


	/**
	 * getLogFile
	 *
	 * gets log file name or multiple log file names for a range of dates depending on logger
	 * and retunrs to module in a array to parse later on
	 *
	 */

	public function getLogFile( $moduleTemplate, $dateRange, $moduleName, $interface = null  )
	{

		//needs error checking here to return null arrays when no log file present
		$logString = null;

		//get the settings for the module
        $moduleSettings = LoadAvg::$_settings->$moduleName; // if module is enabled ... get his settings
       

		if ( LOGGER == "loadavg" ) {
			
			//for loadavg
			//need to loop through range here
			$loop = 0;
			foreach ( $dateRange as $date ) {
					
				$logFile = LOG_PATH . sprintf($moduleTemplate, $date, $interface);

				if ( file_exists( $logFile )) {

					$logString[$loop][0] = $logFile;	
					$loop++;
				}
			}

			return $logString;

		}


		if ( LOGGER == "collectd" ) {

        	//this is only if we are in collectd mode
			$collectdArgs = $moduleSettings['collectd']['args']; 
			$collectd = json_decode($collectdArgs[0]);

			//note that this may change!!!
			//when changing dis mount points in df it changes... from dfboot to df-root
			$moduleTemplate = $collectd->name;


			//need to loop through options here as some log files are split up into multiple files
			$loop = 0;
			foreach ( $dateRange as $date ) {

				//grab all logs for day - only increment array if this loops works
				//hence foundData
				$loop2 = 0;
				$foundData = false;
				foreach ( $collectd->functions as $thedata ) {

					$moduleFunction = $thedata;

					$logFile = COLLECTD_PATH . $moduleTemplate . "/" . $moduleFunction . "-" . $date;
					
					if ( file_exists( $logFile )) {
						$logString[$loop][$loop2] = $logFile;
						$foundData = true;
						$loop2++;
					}
				}

				//only increment here if above loop is successful!
				if  ($foundData) $loop++;
			}

			return $logString;
		}			
	}

	/**
	 * parseLogFileData
	 *
	 * returns data from inside log file in array
	 * if $data is array then grabs multiple files of data - needed when log data is
	 * split acorss mutiple files
	 *
	 */

	public function parseLogFileData( $data, &$newDataArray  )
	{

		//do some checks first
		if ( !$data || $data == null || !isset($data) )
			return false;

		//loop through all data files and add them up here
		$contents = "";
		$loop = 0;

		//loops through each DAY of data as per the depth of the array
		$singleloop = false;

		foreach ($data as $dataKey => $dataData) {
	    	
	    	//for single log files no problem just read it in
			if  ( count($dataData) == 1) {

				if ( file_exists( $dataData[0] )) {

					$contents = file_get_contents($dataData[0]);
					$contents = explode("\n", $contents);

					//used just for collectd to clean top of datasets where descriptions are
					if (LOGGER == "collectd"){
						array_shift($contents);
					}

					//if last value is null or empty or ???? delete it
					if ( end($contents) == null || end($contents) == "" )
						array_pop($contents);

					$DataArray = array_merge($newDataArray, $contents);
					$newDataArray = $DataArray;

				}

			}

			//if there are multiple log files parse it in using
			//getDimensionalLogFileData function below
			if  ( count($dataData) > 1) {

				$contents = $this->getDimensionalLogFileData($dataData);
				$DataArray = array_merge($newDataArray, $contents);
				$newDataArray = $DataArray;

			}

		}

		return true;
	}


	/**
	 * getDimensionalLogFileData
	 *
	 * used when there are multiple log files making up a data point
	 *
	 */

	public function getDimensionalLogFileData( $data  )
	{

		//we need to loop through data and build conetnts array which is exploded array
		//of data files read from disk!

		$loop = 0;
		$arraysize = 0;
		foreach ($data as $dataKey => $dataData) {

			if ( file_exists( $dataData )) {

				$mycontents[$loop] = file_get_contents($dataData);
				$mycontents[$loop] = explode("\n", $mycontents[$loop]);

				$loop++;
				$arraysize++;
			}
		}

		//now we loop through mycontents and build thenew array
		$thenewarray = null;

		//note delimeter is always comma here as currently this is only for collectd csv files
		$delimiter = ",";

		for ($dataloop = 0; $dataloop < $arraysize; $dataloop++) {

			$loop = 0;
			foreach ($mycontents[$dataloop] as &$value) {
				$thedata = explode($delimiter , $value);

				//for first data set grab timestamp
				if ($dataloop==0)
					$thenewarray[0][$loop] = isset($thedata[0]) ? $thedata[0] : null;
				
				//all other data sets its the 2nd value
				$thenewarray[$dataloop+1][$loop] = isset($thedata[1]) ? $thedata[1] : null;
			    $loop++;
			}
			unset($value); // break the reference with the last element

		} 


		//////////////////////////////////////////////////////
		//now build data  - stich it up

		$finaldata = "";
		$loop = 0;
		foreach ($thenewarray[0] as &$value) {

			$dataString = "";
			for ($dataloop = 0; $dataloop <= $arraysize; $dataloop++) {
				$dataString .= $thenewarray[$dataloop][$loop] . ",";
			}
			
			//need to kill the last "," here as its not needed ?
			$dataString = substr($dataString, 0, -1);
			$finaldata[$loop] = $dataString;

		    $loop++;

		}
		unset($value); 

		//clean final array of dead data values as csv files have headers
		array_shift($finaldata);

		//collectd csv files also have a empty last row so kill that
		if ( end($finaldata) == null || end($finaldata) == "" )
				array_pop($finaldata);

		return $finaldata;		
		
	}



	/*
	 * build the chart data array here and patch to check for downtime
	 * as current charts connect last point to next point
	 * so when we are down / offline for more than logging interval
	 * charts dont accuratly show downtime
	 *
	 * send over chartData and contents
	 * return data in chartData
	 * dataset lets you grab the last N hours of the chart if you want a subset
	   can be expanded on to get a subset with starttime / endtime 
	 */

//parses contents
//returns in chartData

	function getChartData (array &$chartData, array &$contents,  $dataSet = 24 ) 
	{				


		//delimiter is based on logger type 
		$delimiter = "";
		switch ( LOGGER ) {

			case "collectd": 	$delimiter = ",";				
								break;

			case "loadavg": 	$delimiter = "|";				
								break;

			default: 			$delimiter = "|";				
								break;				
		}

		// this is based on logger interval of 5, 5 min = 300 aprox we add 100 to be safe
		//$interval = 360;  // 5 minutes is 300 seconds + slippage of 20% aprox 
		$interval = $this->getLoggerInterval();

		if (!$interval)
			$interval = 360; //default interval of 5 min
		else
			$interval = $interval * 1.2; //add 20% to interval for system lag

		$patch = $chartData = array();
		$numPatches = 0;


		//trim the dataset if we are only reading 6 or 12 hours of info
		//revise for 6 and 12 hour charts
		if ( $dataSet == 6 || $dataSet == 12 )
		{
			$totalContents= (int)count( $contents );
			//echo 'TOTAL ' . $totalContents;

			//logger is 5 min
			//so 60 / 5 = 12 datasets we need for 1 hour
			//need to revise if logger is a different time

			$dataNeeded = 12 * $dataSet; 

			//TODO: only trim if there is more than we need...
			$contents = array_slice($contents, ($totalContents - $dataNeeded) );     
		}

		//get size of array for parsig
		$totalContents= (int)count( $contents );


		//if there is only one item in data set then we just chart it
		if ($totalContents == 1) {

			$data = explode($delimiter, $contents[0]);
			$chartData[0] = $data;

		} else {


			//if there is more than one item in dataset then we can check for downtime between points
			//subtract one from totalContents as arrays start at 0 not 1

			for ( $i = 0; $i <= $totalContents-1; ++$i) {

				//used to pull a section of charts for data set zooming 
				//if ($i == 20) break;

				$data = explode($delimiter, $contents[$i]);
				$chartData[$i] = $data;


				/*
				 * check if difference is more than logging interval and patch
				 * we patch for time between last data (system went down) and next data (system came up)
				 * need to check if we need the nextData patch as well ie if system came up within 
				 * the next interval time
				 * 
				 * for local data we dont check the first value in the data set
				 */
				if ($i > 0 && $i < $totalContents-1 ) {

				//dont do this for last value in dataset! as it can have no difference
				$nextData = explode($delimiter, $contents[$i+1]);
				
				//difference in timestamps
				$difference = $nextData[0] - $data[0];

					if ( $difference >= $interval ) {

						//echo 'patch difference:' . $difference;

						$patch[$numPatches] = array(  ($data[0]+$interval), "-1", "-1", "-1", $i);
						$patch[$numPatches+1] = array(  ($nextData[0]- ($interval/2)), "-1", "-1", "-1", $i);

						$numPatches += 2;
					}	
				}
			}

		}
		
		//iterates through the patcharray and patches dataset
		//by adding patch points
		$totalPatch= (int)count( $patch );
		//echo "PATCHCOUNT: " . $totalPatch . "<br>";

		if ($totalPatch >0) {

			for ( $i = 0; $i < $totalPatch ; ++$i) {
					
					$patch_time = ( ($patch[$i][4]) + $i );
					
					// this unset should work to drop recorded patch time ? 
					// but messes up sizeof patcharray				
					$thepatch[0] = array ( $patch[$i][0] , $patch[$i][1] , $patch[$i][2] , $patch[$i][3] );

					array_splice( $chartData, $patch_time, 0, $thepatch );
	        		//echo "PATCHED: " . $patch_time . " count: " . count( $chartData ) . "<br>";

			}
		}

		//echo "PATCHARRAYPATCHED: " . count( $chartData ) . "<br>";
}

	/**
	 * getEmptyChart
	 *
	 * Returns data used to chart a empty chart for when there is no chart data
	 *
	 * @param array $emptyChart array with empty chart data
	 */

	public function getEmptyChart( )
	{
		$emptyChart = array(
			'chart_format' => 'line',
			'ymin' => 0,
			'ymax' => 1,
			'xmin' => date("Y/m/d 00:00:01"),
			'xmax' => date("Y/m/d 23:59:59"),
			'mean' => 0,
			'dataset_1_label' => "No Data",
			'dataset_1' => "[[0, '0.00']]"
		);

		return $emptyChart;
	}


	/**
	 * parseInfo
	 *
	 * Parses ini file data for a module into lines of text for legend display
	 *
	 * @param array $info array with info lines from the classes INI file
	 * @param array $variables variables to format lines
	 * @param string $class class name of module
	 * @return array $return formatted info lines
	 */

	public function parseInfo( $info, $variables, $class )
	{
		$return = array();
		
		foreach ( $info as $line ) {
			$line = json_decode($line);

			if (isset($line->type) && isset($line->filename)) {
				$return['info']['line'][] = array("type" => "file", "file" => 'modules' . DIRECTORY_SEPARATOR . $class . DIRECTORY_SEPARATOR . $line->filename);
				continue;
			}

			if ( strstr($line->args, "|")) {
				$lineArgs = explode("|", $line->args);
				$args = array();
				foreach ($lineArgs as $arg) {
					$args[] = $variables[$arg];
				}
				$line = vsprintf($line->format, $args);
			} else {
				$line = sprintf($line->format, $variables[$line->args]);
			}
			$return['info']['line'][] = array( "type" => "line", "formatted_line" => $line );
		}

		return $return;
	}

	/**
	 * write_php_ini
	 *
	 * Writes data into INI file
	 *
	 * @param array $array array with data to write into INI file.
	 * @param string $file filename to write.
	 */

	public static function write_php_ini($array, $file)
	{
	    $res = array();
		$bval = null;
	    foreach($array as $key => $val)
	    {
	        if(is_array($val))
	        {
	            $res[] = "[$key]";
	            foreach($val as $skey => $sval) {
			if (is_array($sval)) {
				for ($i = 0; $i < count($sval); $i++) {
					$res[] = $skey . '[] = \'' . $sval[$i] . '\'';
				}
			} else {
	        	    	if (strpos($sval, ";") === 0)
		            		$res[] = $sval;
		            	else
	            			$res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
			}
	            }
	        }
	        else {
	        	if (strpos($val, ";") === 0)
	        		$res[] = $val;
	        	else
	        		$res[] = "$key = ".(is_numeric($val) ? $val : (strstr($val, '{') !== false) ? '\''.$val.'\'' : '"'.$val.'"');
	        }
	    }

	    //we should use this instead
	    //LoadAvg::safefilerewrite($file, implode("\r\n", $res));

	    //security header here
	    $header = "; <?php exit(); __halt_compiler(); ?>\n";

	    if ($fp = fopen($file, 'w') ) {
	    	fwrite($fp, $header);	    	
	    	fwrite($fp, implode("\r\n", $res));
	    	fclose($fp);
	    }
	}

	//modified to not clean numeric values
	/*
	function write_php_ini($array, $file)
	{
	    $res = array();
	    foreach($array as $key => $val)
	    {
	        if(is_array($val))
	        {
	            $res[] = "[$key]";

	            //foreach($val as $skey => $sval) $res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
	            foreach($val as $skey => $sval) 
	            	$res[] = "$skey = ".'"'.$sval.'"';
	        }
	        //else $res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
	        else $res[] = "$key = ".'"'.$val.'"';
	    }
	    safefilerewrite($file, implode("\r\n", $res));
	}
	*/



	public static function write_module_ini($newsettings, $module_name)
	{

		$module_config_file = HOME_PATH . '/lib/modules/' . $module_name . '/' . strtolower( $module_name ) . '.ini.php';

		//$this->write_php_ini($newsettings, $module_config_file);
		self::write_php_ini($newsettings, $module_config_file);

	}

	/**
	 * safefilewrite
	 *
	 * Writes data to INI file and locks the file
	 *
	 * @param string $fileName filename
	 * @param array $dataToSave data to save to file
	 */

	public function safefilerewrite($fileName, $dataToSave, $mode = "w", $logs = false )
	{    

		//if file is new and is a logfile then we need to make it chmod 777
		//or we have issues between flies create using app and ones using cron
		//cron gives root permissions and app gives appache permissions
		$exists = file_exists ( $fileName );

		if ($fp = fopen($fileName, $mode))
	    {
	        $startTime = microtime();
	        do
	        {
	        	$canWrite = flock($fp, LOCK_EX);
	        	// If lock not obtained sleep for 0 - 100 milliseconds, to avoid collision and CPU load
	        	if(!$canWrite) usleep(round(rand(0, 100)*1000));
	        } while ((!$canWrite)and((microtime()-$startTime) < 1000));

	        //file was locked so now we can store information
	        if ($canWrite)
	        {
	        	fwrite($fp, $dataToSave);
	            //flock($fp, LOCK_UN);
	        }

	        fclose($fp);

	        //if its a new log file fix permissions
	        if (!$exists && $logs==true ) {
	        	//echo "fix logs";
				chmod($fileName, 0777);
			}

	        return true;
	    }
	    else
	    {
	    	return false;
	    }

	}


	/**
	 * ini_merge
	 *
	 * used in settings modules to merge changes inot settings files
	 * may be depreciated now in exchange for array_replace
	 *
	 * @param string $config_ini config file array
	 * @param string $custom_ini data config file array to merge with
	 */

	 public static function ini_merge ($config_ini, $custom_ini) 
	 {
	 	foreach ($custom_ini AS $k => $v):
	    	if (is_array($v)):
	      		$config_ini[$k] = self::ini_merge($config_ini[$k], $custom_ini[$k]);
	    	else:
	      		$config_ini[$k] = $v;
	    	endif;
	  	endforeach;
	 
	 	return $config_ini;
	 }
	/**
	 * getLoggerInterval
	 *
	 * User login, checks username and password from default settings to match.
	 *
	 * @param string $username the username
	 * @param string $password the password
	 */

	public function getLoggerInterval( ) 
	{

		$interval = LoadAvg::$_settings->general['logger_interval'];

		if  ( $interval ) {

			$interval = $interval * 60;
			return $interval;

		} else {

			return false;
		}

	}




	/**
	 * sendApiData
	 *
	 * If API is activated sends data to server
	 *
	 * @param array $data array of data to send to the server
	 * @return string $result message returned from the server
	 */

	public function sendApiData( $data ) {

		// for debugging
		//var_dump($data); //exit;
		//echo 'DEBUG: ' .  json_encode($data);

		$url = self::$_settings->general['api']['url'];

		$user_url = $url . '/users/';
		$server_url = $url . '/servers/';

		//validate API access here
		if ( self::$_settings->general['api']['server_token'] && self::$_settings->general['api']['key'] ) {		
		$ch =  curl_init($server_url . self::$_settings->general['api']['server_token'] . '/' . self::$_settings->general['api']['key'] . '/v');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$account_valid = curl_exec($ch);
		} else
			$account_valid = 'false';

		//get server id from server token
		if ( self::$_settings->general['api']['server_token'] ) {			
		$ch =  curl_init($server_url . self::$_settings->general['api']['server_token'] . '/t');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$server_exists = curl_exec($ch);
		} else
			$server_exists = 'false';

		//echo $server_url.json_decode($server_exists)->id.'/data';

		//validation needs to happen on the sever this is still insecure!
		//need to pass api token and server token over in data push

		if( $server_exists != 'false' && $account_valid != 'false' ) 
		{

			//file_put_contents("file.txt", json_encode($data)); test data
			$curl = curl_init();

			// Set some options - we are passing in a useragent too here
			curl_setopt_array($curl, array(
		    CURLOPT_RETURNTRANSFER => 1,
		    CURLOPT_URL => $server_url.json_decode($server_exists)->id.'/data',
		    CURLOPT_USERAGENT => 'LoadAvg Client',
		    CURLOPT_POST => 1,
		    CURLOPT_POSTFIELDS => array(
		      'data' => json_encode($data),
		    )
			));

			// Send the request & save response to $resp
			$resp = curl_exec($curl);

			// Close request to clear up some resources
			curl_close($curl);

			//used for debugging to file
			//file_put_contents("file.txt",$resp);
			
			return true;
		}

		return null;
	}


	/**
	 * testApiConnection
	 *
	 * Test if API connection is working
	 *
	 * @return string $result message returned from the server
	 */

	public static function testApiConnection( $echo = false ) {

		$url = self::$_settings->general['api']['url'];

		$user_url = $url . '/users/';
		$server_url = $url . '/servers/';
		
		//validate users api key
		if ( self::$_settings->general['api']['key'] ) {
			$ch =  curl_init($user_url . self::$_settings->general['api']['key'] . '/va');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$user_exists = curl_exec($ch);
		} else
			$user_exists = 'false';


		//val;idate server token
		if ( self::$_settings->general['api']['server_token'] ) {
			$ch =  curl_init($server_url . self::$_settings->general['api']['server_token'] . '/vs');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$server_exists = curl_exec($ch);
		} else
			$server_exists = 'false';		

		//validate api key against server token
		if ( self::$_settings->general['api']['server_token'] && self::$_settings->general['api']['key'] ) {
			$ch =  curl_init($server_url . self::$_settings->general['api']['server_token'] . '/' . self::$_settings->general['api']['key'] . '/v');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$server_valid = curl_exec($ch);
		} else
			$server_valid = 'false';

		if ($echo) {

			echo ($user_exists == 'false' ?  "API Key : INVALID \n" :  "API Key : VALID \n");

			echo ($server_exists == 'false' ?  "Server Token : INVALID \n" :  "Server Token : VALID \n");
			
			echo ($server_valid == 'false' ?  "Server Access : INVALID \n" :  "Server Access : VALID \n");

		}

		//return server valid status
		if($server_valid == 'false') 
			return false;
		else
			return true;
	}

	/**
	 * logIn
	 *
	 * User login, checks username and password from default settings to match.
	 *
	 * @param string $username the username
	 * @param string $password the password
	 */

	public function logIn( $username, $password ) 
	{
		if ( isset($username) && isset($password) ) 
		{
			if ($username == LoadAvg::$_settings->general['username'] && md5($password) == LoadAvg::$_settings->general['password']) 
			{
				$_SESSION['logged_in'] = true;

				if (isset(self::$_settings->general['checkforupdates'])) 
				{
					//check for updates at login
					$this->checkForUpdate();
				}


				if($_POST['remember-me']) {

					$cookie_time = self::$_settings->general['rememberme_interval'];

					if ( $cookie_time <1 || !$cookie_time )
						$cookie_time = 1;

					$cookietime = time() + (86400 * $cookie_time); // 1 day

					setcookie('loadremember', true, $cookietime);
					setcookie('loaduser', $username, $cookietime);
					setcookie('loadpass', md5($password), $cookietime);
				}
				elseif(!$_POST['remember-me']) {

					$past = time() - 100;

					if( isset($_COOKIE['loadremember']) ) 
						setcookie('loadremember', 0, $past);

					if(isset($_COOKIE['loaduser'])) 
						setcookie('loaduser', 0, $past);

					if(isset($_COOKIE['loadpass'])) 
						setcookie('loadpass', 0, $past);
				}

			}

		}
	}

	/**
	 * isLoggedIn
	 *
	 * Checks if the user is logged in and has SESSION started.
	 *
	 * @return boolean TRUE if is logged in and FALSE if not.
	 */

	public function isLoggedIn()
	{
		if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] == true) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * logOut
	 *
	 * Logs out user and destroys SESSION data.
	 *
	 */

	public static function logOut() { 

		//used to clean up remember me functionality
		$past = time() - 100;

		if(isset($_COOKIE['loaduser'])) 
			setcookie('loaduser', 0, $past);

		if(isset($_COOKIE['loadpass'])) 
			setcookie('loadpass', 0, $past);

		//clean up session
		session_destroy(); 

	}


	/**
	 * checkCookies
	 *
	 * Checks if the user is logged in and has SESSION started.
	 *
	 * @return boolean TRUE if is logged in and FALSE if not.
	 */

	public function checkCookies()
	{

		if ( isset($_COOKIE['loaduser']) && isset($_COOKIE['loadpass']) ) {

			echo 'found cookies';

			if (         $_COOKIE['loaduser'] == LoadAvg::$_settings->general['username'] 
		          &&     $_COOKIE['loadpass'] == LoadAvg::$_settings->general['password'] ) 
			{
				return true;        
			} 
		}

		return false;

	}

	/**
	 * checkIpBan
	 *
	 * Checks if the user is logged in and has SESSION started.
	 *
	 * @return boolean TRUE if is logged in and FALSE if not.
	 */

	public function checkIpBan()
	{

		$blacklist = APP_PATH . '/config/banned_ip.ini';

		//get the ip address best we can
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
		    $ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
		    $ip = $_SERVER['REMOTE_ADDR'];
		}


		if ( file_exists( $blacklist )) {

			$ip_array  = parse_ini_file($blacklist);
			$ip_array = explode ( ',' , $ip_array['banned']);

			if ( in_array($ip,$ip_array)) {
				return true;
				$this->logUpdateCheck( "BANNED LOGIN" . $ip );
			} else {
				return false;				
			}
		}

		return false;
	}

	/**
	 * logFlooding
	 *
	 * Checks if the user is logged in and has SESSION started.
	 *
	 * @return boolean TRUE if is logged in and FALSE if not.
	 */

	public function logFlooding()
	{

		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
		    $ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
		    $ip = $_SERVER['REMOTE_ADDR'];
		}

	    $response = "Login flooding by ip:" . $ip;

		$this->logUpdateCheck( $response );

	}
		
	/**
	 * getNetworkInteraces
	 *
	 * Retrives network interfaces
	 *
	 * @return array $interfaces array of interfaces found on server
	 */

	public static function getNetworkInterfaces()
	{
		// $interfaces = exec("/sbin/ifconfig | grep -oP '^[a-zA-Z0-9]*' | paste -d'|' -s");
		//$interfaces = exec('/sbin/ifconfig | expand | cut -c1-8 | sort | uniq -u | awk -F: \'{print $1;}\' | tr "\\n" "|" | tr -d \' \' | sed \'s/|*$//g\'');

		exec("/sbin/ifconfig", $content);
		$interfaces = array();

		#foreach (preg_split("/\n\n/", $content) as $int) {
		foreach ( $content as $int ) {
		    preg_match("/^(.*)\s+(flags|Link)/ims", $int, $regex);

		        if (!empty($regex)) {
		                $interface = array();
		                //$interface['name'] = $regex[1];

		                //added a trim to the return value as on centos 6.5 we had whitespace
		                $interface['name'] =  trim ( (substr(trim($regex[1]), strlen(trim($regex[1]))-1, strlen(trim($regex[1]))) == ":") ? substr(trim($regex[1]), 0 , strlen(trim($regex[1]))-1) : $regex[1] );

		                //echo ':' . $interface['name'] . ':';

		                $interfaces[] = $interface;
		        }
		}

		return $interfaces;
	}

	/**
	 * getTime
	 *
	 * Sets startTime of page load
	 *
	 */

	public function getTime()
	{
		$time = microtime();
		$time = explode(' ', $time);
		$time = $time[1] + $time[0];
		return $time;
	}

	/**
	 * setStartTime
	 *
	 * Sets startTime of page load
	 *
	 */

	public function setStartTime()
	{
		$time = microtime();
		$time = explode(' ', $time);
		$time = $time[1] + $time[0];
		$this->timeStart = $time;
	}

	/**
	 * setFinishTime
	 *
	 * Sets finish time of page load
	 *
	 */

	public function setFinishTime()
	{
		$time = microtime();
		$time = explode(' ', $time);
		$time = $time[1] + $time[0];
		$this->timeFinish = $time;
	}

	/**
	 * getPageLoadTime
	 *
	 * Returns page load time
	 *
	 * @return string $time page load time
	 */

	public function getPageLoadTime()
	{
		return round( ( $this->timeFinish - $this->timeStart ), 4 );
	}

	/**
	 * getDates
	 *
	 * Gets date range from logfiles to populate the select box from topbar
	 * NOTE: Was changed to static
	 *
	 * @return array $return array with list of dates
	 */

	//grabs dates of ALL log files to be safe but would be faster if it did just one module

	public static function getDates()
	{
		$dates = array();

		foreach ( glob(dirname(__FILE__) . "/../logs/*.log") as $file ) {
			preg_match("/([0-9-]+)/", basename($file), $output_array);
		
			if ( isset( $output_array[0] ) && !empty( $output_array[0] ) )
				$dates[] = $output_array[0];
		}

 		//get rid of all duplicate dates
		$dates = array_unique($dates);

		//need to properly sort the array before returning it
		asort ($dates);

		return $dates;
	}

	/**
	 * logUpdateCheck
	 *
	 * Logs to file every check for update(s).
	 *
	 */

	public function logUpdateCheck( $response )
	{
		$fh = fopen(dirname(__FILE__) . '/../logs/update.log', 'a+');
		$logLine = "Update check at " . date("Y-m-d H:i:s a") . " ---- Response: " . $response . PHP_EOL;
		if ( $fh ) {
			fwrite($fh, $logLine);
			fclose($fh);
		}

	}

	/**
	 * checkForUpdate
	 *
	 * Checks for new versions of LoadAvg
	 *
	 */

public function getLinuxDistro()
    {
        //declare Linux distros(extensible list).
        $distros = array(
                "Arch" => "arch-release",
                "Debian" => "debian_version",
                "Fedora" => "fedora-release",
                "Ubuntu" => "lsb-release",
                'Redhat' => 'redhat-release',
                'CentOS' => 'centos-release');
    //Get everything from /etc directory.
    $etcList = scandir('/etc');

    //Loop through /etc results...
    $OSDistro;
    foreach ($etcList as $entry)
    {
        //Loop through list of distros..
        foreach ($distros as $distroReleaseFile)
        {
            //Match was found.
            if ($distroReleaseFile === $entry)
            {
                //Find distros array key(i.e. Distro name) by value(i.e. distro release file)
                $OSDistro = array_search($distroReleaseFile, $distros);

                break 2;//Break inner and outer loop.
            }
        }
    }

    return $OSDistro;

  }



	public function checkForUpdate()
	{

		$linuxname = "";

		//check that this works with get as its long...
		/*
		<?php
		print_r(posix_uname());
		?>

		Should print something like:

		Array
		(
		    [sysname] => Linux
		    [nodename] => vaio
		    [release] => 2.6.15-1-686
		    [version] => #2 Tue Jan 10 22:48:31 UTC 2006
		    [machine] => i686
		)
		*/
		
		//foreach(posix_uname() AS $key=>$value) {
    		//$linuxname .= $value ." ";
		//}		

		$linuxname = $this->getLinuxDistro();

		if ( !isset($_SESSION['download_url'])) {
			if ( ini_get("allow_url_fopen") == 1) {

				//replace me with curl please!!!
				$response = file_get_contents("http://updates.loadavg.com/version.php?"
					. "ip=" . $_SERVER['SERVER_ADDR'] 
					. "&version=" . self::$_settings->general['version'] 
					. "&site_url=" . self::$_settings->general['title']  
					. "&phpv=" . phpversion()  					 
					. "&osv=" . $linuxname  					 
					. "&key=1");

				// $response = json_decode($response);

				//log the action locally
				$this->logUpdateCheck( $response );

				 	$_SESSION['download_url'] = "http://www.loadavg.com/download/";

				if ( $response > self::$_settings->general['version'] ) {
				 	$_SESSION['download_url'] = "http://www.loadavg.com/download/";
				}
			}
		}
	}



	/**
	 * getTimezones
	 *
	 * Get the (cached) list of all possible timezones
	 *
	 */

	public static function getTimezones()
	{
		if (is_array(LoadAvg::$_timezones)) {
			return LoadAvg::$_timezones;
		}

		LoadAvg::$_timezones = array();

		$regions = array(
		    'Africa' => DateTimeZone::AFRICA,
		    'America' => DateTimeZone::AMERICA,
		    'Antarctica' => DateTimeZone::ANTARCTICA,
		    'Aisa' => DateTimeZone::ASIA,
		    'Atlantic' => DateTimeZone::ATLANTIC,
		    'Europe' => DateTimeZone::EUROPE,
		    'Indian' => DateTimeZone::INDIAN,
		    'Pacific' => DateTimeZone::PACIFIC
		);

		foreach ($regions as $name => $mask)
		{
		    $zones = DateTimeZone::listIdentifiers($mask);
		    foreach($zones as $timezone)
		    {
				// Lets sample the time there right now
				$time = new DateTime(NULL, new DateTimeZone($timezone));

				// Us dumb Americans can't handle millitary time
				$ampm = $time->format('H') > 12 ? ' ('. $time->format('g:i a'). ')' : '';

				// Remove region name and add a sample time
				LoadAvg::$_timezones[$name][$timezone] = substr($timezone, strlen($name) + 1) . ' - ' . $time->format('H:i') . $ampm;
			}
		}

		return LoadAvg::$_timezones;

	}

}
