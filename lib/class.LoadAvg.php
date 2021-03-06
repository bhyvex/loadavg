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

	public static $settings_ini; //location of settings.ini file
	public static $_settings; // storing standard settings and/or loaded modules settings
	
	public static $current_date; // current date
	//private static $_timezones; // Cache of timezones

	// Periodas
	public static $period;
	public static $period_minDate;
	public static $period_maxDate;

	//hack for mobile
	public static $isMobile;

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

		//load in settings.ini file
		self::$settings_ini = "settings.ini.php";

		$this->setSettings('general',
			parse_ini_file(APP_PATH . '/config/' . self::$settings_ini, true)
		);

		//these updates really need to be moved into installer
		//but are here for when people pull form git

		//check for old 2.0 settings config here....
		if ( !isset( self::$_settings->general['settings']) ) {

			//for legacy 2.0 upgrade support
			$this->upgradeSettings(true);
		}

		//check for upgrade for 2.1 here....
		//we only do this if no install folder is present mate
		if ( ($this->checkInstaller() == true) && (self::$_settings->general['settings']['version'] == "2.1") )
			$this->upgradeSettings21to22();

		//get the date and timezone

		//date_default_timezone_set("UTC");
		date_default_timezone_set(self::$_settings->general['settings']['timezone']);

		//if no log date is set then use todays date
		self::$current_date = (isset($_GET['logdate']) && !empty($_GET['logdate'])) ? $_GET['logdate'] : date("Y-m-d");

		//mobile status
		self::$isMobile = false;


	}


	/**
	 * upgradeSettings
	 *
	 * upgrades 2.1 to 2.2 can be depreciated later on and moved into installer
	 *
	 * @param string $dir path to directory
	 */

	//public is a security hole but needed for installer to call this file!
	public function upgradeSettings21to22($reload = true) {

		//echo 'update';
		//die;
		
		$settings_file = HOME_PATH . '/app/config/' . LoadAvg::$settings_ini;

		//get current settings
		$settings = LoadAvg::$_settings->general;

		//add new stuff here
		$settings['settings']['version'] = '2.2';

		//update core settings with new features

		if ( !isset ( $settings['settings']['clienttimezone'] ) ) 
	    	$settings['settings']['clienttimezone'] = "America/New_York";

		if ( !isset ( $settings['settings']['timezone'] ) ) 
	    	$settings['settings']['timezone'] = "America/New_York";

		if ( !isset ( $settings['settings']['timezonemode'] ) ) 
	    	$settings['settings']['timezonemode'] = "Browser";

		if ( !isset ( $settings['settings']['logalerts'] ) ) 
	    	$settings['settings']['logalerts'] = "true";

		//update core modules with new modules

		if ( !isset ( $settings['modules']['Miner'] ) ) 
	    	$settings['modules']['Miner'] = "false";    

		//update core plugins with new plugins

		if ( !isset ( $settings['plugins']['Alerts'] ) ) 
	    	$settings['plugins']['Alerts'] = "true";   

		if ( !isset ( $settings['plugins']['Process'] ) ) 
	    	$settings['plugins']['Process'] = "true";  

		if ( !isset ( $settings['plugins']['Server'] ) ) 
	    	$settings['plugins']['Server'] = "true";  


		//echo '<pre>'; var_dump ($settings); echo '</pre>';

		LoadUtility::write_php_ini($settings, $settings_file);

		//clear update cookies
		$this->checkForUpdate();

		//relaod the app now
        if ($reload) {
        	header("Location: index.php");
        }

		//die;
	}

	/**
	 * upgradeSettings
	 *
	 * upgrades from legacy 2.0 version to 2.1 can be depreciated late on
	 *
	 * @param string $dir path to directory
	 */

	private function upgradeSettings() {

		$settingsFileLocation = APP_PATH . '/config/' . self::$settings_ini;

		if ( file_exists( $settingsFileLocation )) {

			$settingsFile = file_get_contents($settingsFileLocation);
			$settingsFile = explode("\n", $settingsFile);

			//var_dump ($settingsFile);

			//insert settings moduel header
			$inserted = "[settings]";
			array_splice( $settingsFile, 1, 0, $inserted ); // splice in at position 3
			
			//delete server setting from modules
			$needle = 'Server';
			foreach($settingsFile as $key => $item) {
  				if(strpos($item,$needle)!== false) {
					$item = (int)$key;
    					unset ($settingsFile[$key]);
  				}
			}

			//delete blank line from end of file
			if ( end($settingsFile) == "" || end($settingsFile) == null )
				array_pop($settingsFile);

			//add new plugins section
			array_push($settingsFile, '[plugins]');

			//var_dump ($settingsFile);
			//die;

			//now wite settings back out
		    if ($fp = fopen($settingsFileLocation, 'w') ) {
		    	fwrite($fp, implode("\n", $settingsFile));
		    	fclose($fp);
		    }

			//reload settings now
			$this->setSettings('general',
				parse_ini_file(APP_PATH . '/config/' . self::$settings_ini, true)
			);		    
		}
	}



	public function memoryDebugData( $memory_usage) {

		echo '<pre>';
		echo "Memory at Start        :" . $memory_usage['start'] . "\n"; 
		echo "After class.Utility.php:" . $memory_usage['utility'] . "\n"; 
		echo "After class.LoadAvg.php:" . $memory_usage['loadavg'] . "\n"; 
		echo "After class.Modules.php:" . $memory_usage['modules'] . "\n"; 
		echo "After class.Plugins.php:" . $memory_usage['plugins'] . "\n"; 
		echo "After class.Timer.php  :" . $memory_usage['timer'] . "\n"; 
		echo '</pre>';

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


		if ( LoadUtility::is_dir_empty(HOME_PATH . '/' . self::$_settings->general['settings']['logs_dir']) ) {

			$this->runLogger();
		}


	}

	/**
	 * runLogger
	 *
	 * Creates first log files for every loaded modules 
	 * only run once - after installation - and may not be needed as modules will do this themselves
	 * if the file isnt there they create it
	 *
	 */

	/*
	 * also used when we turn modules on and off
	 * this needs to only build the log file for modules that have no log file in /logs
	 * also be great to pass the module over if we know 
	 * what module has changed or been enabled
	 */

	public function runLogger()
	{

		$php_location = PHP_BINDIR . "/php ";

		$runLogger = $php_location . dirname(APP_PATH) . "/logger.php";
		$runLoggerStatus = $php_location . dirname(APP_PATH) . "/logger.php" . " status ";

		//echo 'log exec : ' . $runLogger . '<br>';

		exec( $runLogger );
		$loggerStatus = exec( $runLoggerStatus );

		//echo 'log status : ' . $loggerStatus ;

		//return true or false base don logger status...


	}



/*
 * used to test if log files are being created by logger
 * needs better testing currently a bit of a hack
 * as we just test if the log directory is empty or not
 */

 	function testLogs( $mode = true)
	{

			$loadedModules = self::$_settings->general['modules'];
			$logdir = HOME_PATH . '/' . self::$_settings->general['settings']['logs_dir'];

			$test_worked = false;
			$test_nested = false;

			if ( LoadUtility::is_dir_empty($logdir))
				return false;

			// Check for each module we have loadedModules
			foreach ( $loadedModules as $module => $value ) {
				if ( $value == "false" || ( !isset(LoadModules::$_settings->$module)    )  ) continue;


				$moduleSettings = LoadModules::$_settings->$module;


				// Check if loadedModules module needs loggable capabilities
				if ( $moduleSettings['module']['logable'] == "true" ) {
					
					foreach ( $moduleSettings['logging']['args'] as $args) {
						
						$args = json_decode($args);
						$class = LoadModules::$_classes[$module];
						
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
		//if ( $this->checkWritePermissions( $settings_file ) ) 
		if ( LoadUtility::checkWritePermissions( $settings_file ) ) 
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
	 * getDateRange
	 *
	 * gets the current data or date range and returns to module
	 * will return date range or current date if no range
	 *
	 */

	//TODO: update this to work with collectd dates
	//fix ranges up to todays date as well

	public function getDateRange ()
	{

		/* 
		 * Grab the current period if a period has been selected
		 * TODO: if min date and no max date then set max date to todays date
		 * max date alone does nothing...
		 */
	
		$minDate = $maxDate = false;

		if ( isset($_GET['minDate']) && !empty($_GET['minDate']) ) 
			$minDate = true;

		if ( isset($_GET['maxDate']) && !empty($_GET['maxDate']) ) 
			$maxDate = true;


		//for some reason when max date is todays date we cant plot the range ?

		if ( $minDate  )
		{
			LoadAvg::$period = true;
			LoadAvg::$period_minDate = date("Y-m-d", strtotime($_GET['minDate']));

			if ($maxDate)
				LoadAvg::$period_maxDate = date("Y-m-d", strtotime($_GET['maxDate']));
			else
				LoadAvg::$period = false;

			//else
			//	LoadAvg::$period_maxDate = self::$current_date;
		}

		//echo "minDate" . LoadAvg::$period_minDate . "<br>";
		//echo "maxDate" . LoadAvg::$period_maxDate . "<br>";

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
			if ($username == LoadAvg::$_settings->general['settings']['username'] && md5($password) == LoadAvg::$_settings->general['settings']['password']) 
			{
				$_SESSION['logged_in'] = true;

				if (isset(self::$_settings->general['settings']['checkforupdates'])) 
				{
					//check for updates at login
					$this->checkForUpdate();
				}


				if($_POST['remember-me']) {

					$cookie_time = self::$_settings->general['settings']['rememberme_interval'];

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

				$ip = $this->getUserIP ();
				$this->logUpdateCheck( "User logged in " . date('l jS \of F Y h:i:s A') . ' from ' . $ip );

			}

		}
	}




	public function getUserIP () {

		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
		    $ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
		    $ip = $_SERVER['REMOTE_ADDR'];
		}

		return $ip;

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

		//$this->logUpdateCheck( "User logged out" . getdate() );

		//clean up session
		session_destroy(); 

	}



	public function checkForUpdate()
	{

		$linuxname = "";

		//check that this works with get as its long...
		/*
		print_r(posix_uname());

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

		if ( !isset($_SESSION['updateStatus'])) {
			if ( ini_get("allow_url_fopen") == 1) {

				//replace me with curl please!!!
				$response = file_get_contents("http://updates.loadavg.com/version.php?"
					. "ip=" . $_SERVER['SERVER_ADDR'] 
					. "&version=" . self::$_settings->general['settings']['version'] 
					. "&site_url=" . self::$_settings->general['settings']['title']  
					. "&phpv=" . phpversion()  					 
					. "&osv=" . $linuxname  					 
					. "&key=1");

				// $response = json_decode($response);

				//log the action locally - need to use log for more things its great
				if ($response != false) {

					$this->logUpdateCheck( "Check for udpates returned " . $response );

					$serverVersion = floatval($response);
					$localVersion = floatval (self::$_settings->general['settings']['version']);


					if ( $serverVersion > $localVersion ) {
					 	$_SESSION['updateStatus'] = "outdated";
					} else if ( $serverVersion < $localVersion ) {
					 	$_SESSION['updateStatus'] = "developer";						
					} else {
					 	$_SESSION['updateStatus'] = "uptodate";						
					}
				} else {
					$_SESSION['updateStatus'] = "offline";						
				}


			}
		}

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

			if (         $_COOKIE['loaduser'] == LoadAvg::$_settings->general['settings']['username'] 
		          &&     $_COOKIE['loadpass'] == LoadAvg::$_settings->general['settings']['password'] ) 
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





}
