<?php
	require_once dirname(__FILE__) . '/vendor/autoload.php';
	require_once dirname(__FILE__) . '/cast/Chromecast.php';
    require_once dirname(__FILE__) . '/util.php';
    require_once dirname(__FILE__) . '/body.php';
	//require_once dirname(__FILE__) . '/new_body.php';

	use Kryptonit3\SickRage\SickRage;
    use Kryptonit3\Sonarr\Sonarr;

	$config = new Config_Lite('config.ini.php');
	setDefaults();
    checkSignIn();
    $user = validateCredentials();
    if ($user['valid']) {
	    if ( session_started() === FALSE ) {
		    session_id($user['apiToken']);
		    session_start();
	    }
	    $_SESSION['plexUserName'] = $user['plexUserName'];
	    $_SESSION['apiToken'] = $user['apiToken'];
	    $_SESSION['plexToken'] = $user['plexToken'];
	    setSessionVariables();
    	initialize();
    } else {
    	write_log("Sorry, couldn't validate user.");
	    if (isset($_GET['testclient'])) {
		    write_log("API Link Test FAILED!  Invalid API Token.");
		    echo 'Invalid API Token Specified! <br>';
	    }
	    write_log("ERROR: Unauthenticated access detected.  Originating IP - ".$_SERVER['REMOTE_ADDR'],"ERROR");
	    $entityBody = curlGet('php://input');
	    if ($_SERVER['REQUEST_METHOD'] === 'POST') write_log("Post BODY: ".$entityBody);
	    die();
    }

    function initialize() {
    	sessionData();
	    if (isset($_POST['username']) && isset($_POST['password'])) {
		    define('LOGGED_IN', true);
		    if (isset($_POST['new'])) {
			    echo makeNewBody();
		    } else {
		    	echo makeBody();
		    }
		    die();
	    }
	    write_log('_______________________________________________________________________________');
	    write_log('-------------------------------- SESSION START --------------------------------');
	    write_log((isset($_SESSION['plexToken']) ? "Session token is set." : "Session token not found."));
	    write_log((isset($_SESSION['plexUserName']) ? "Session username is set to " . $_SESSION['plexUserName'] : "Session token not found."));
	    write_log("Valid plex token used for authentication.");

	    // Handler for API.ai calls.


	    // If we are authenticated and have a username and token, continue

	    if (!(isset($_SESSION['counter']))) {
		    $_SESSION['counter'] = 0;
	    }


	    if (isset($_GET['pollPlayer'])) {
		    $result['playerStatus'] = playerStatus();
		    $file = 'commands.php';
		    $handle = fopen($file, "r");
		    //Read first line, but do nothing with it
		    fgets($handle);
		    $contents = '[';
		    //now read the rest of the file line by line, and explode data
		    while (!feof($handle)) {
			    $contents .= fgets($handle);
		    }
		    $result['commands'] = urlencode(($contents));
		    $devices = scanDevices();
		    $result['players'] = fetchClientList($devices);
		    $result['servers'] = fetchServerList($devices);
		    $result['dvrs'] = fetchDVRList($devices);
		    header('Content-Type: application/json');
		    echo JSON_ENCODE($result);
		    die();
	    }

	    if ((isset($_GET['getProfiles'])) && (isset($_GET['service']))) {
		    $service = $_GET['service'];
		    write_log("Got a request to fetch the profiles for " . $service);
	    }

	    if (isset($_GET['testclient'])) {
		    write_log("API Link Test successful!!");
		    write_log("API Link Test successful!!");
		    echo 'success';
		    die();
	    }

	    if (isset($_GET['test'])) {
		    $result = array();
		    $result['status'] = testConnection($_GET['test']);
		    header('Content-Type: application/json');
		    echo JSON_ENCODE($result);
		    die();
	    }

	    if (isset($_GET['registerServer'])) {
		    registerServer();
		    echo "OK";
		    die();
	    }

	    if (isset($_GET['card'])) {
		    echo JSON_ENCODE(popCommand($_GET['card']));
		    die();
	    }

	    if (isset($_GET['device'])) {
	    	write_log("SETTING DEVICE HERE");
		    write_log("SETTING DEVICE HERE");
		    foreach($_GET as $name=>$param) {
		    	write_log("Device Param ".$name.": ".$param);
		    }
		    $type = $_GET['device'];
		    $id = $_GET['id'];
		    $uri = $_GET['uri'];
		    $publicUri = $_GET['publicuri'] ?? $uri;
		    $name = $_GET['name'];
		    $product = $_GET['product'];
		    write_log('GET: New device selected. Type is ' . $type . ". ID is " . $id . ". Name is " . $name);
		    if ($id != 'rescan') {
			    if ($type == 'plexServerId') {
				    $token = $_GET['token'];
				    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $type . 'Token', $token);
			    }
			    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $type, $id);
			    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $type. 'Id', $id);
			    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $type . 'Uri', $uri);
			    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $type . 'publicUri', $publicUri);
			    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $type . 'Name', $name);
			    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $type . 'Product', $product);
			    saveConfig($GLOBALS['config']);
			    setSessionVariables();
			    sessionData();
			    write_log("Refreshing devices of " . $type);
			    scanDevices();
		    } else {
			    scanDevices(true);
		    }
		    die();
	    }

	    // If we are changing a setting variable via the web UI.
	    if (isset($_GET['id'])) {
		    $id = $_GET['id'];
		    $value = $_GET['value'];
		    write_log('GET: Setting parameter changed ' . $id . ' : ' . $value);
		    if (preg_match("/IP/", $id)) {
			    write_log("Got a URL: " . $value);
			    $value = addScheme($value);
		    }
		    if (is_bool($value) === true) {
			    $GLOBALS['config']->setBool('user-_-' . $_SESSION['plexUserName'], $id, $value);
		    } else {
			    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $id, $value);
		    }
		    saveConfig($GLOBALS['config']);

		    if (trim($id) === 'useCast') {
			    $_SESSION['useCast'] = $value;
			    scanDevices(true);
		    }
		    if (trim($id) === 'cleanLogs') {
			    $_SESSION['cleanLogs'] = $value;
		    }

		    if (trim($id) === 'darkTheme') {
			    write_log("API: Re-generating body.");
			    echo "DONE";
		    }
		    setSessionVariables();
		    die();
	    }


	    if (isset($_GET['TEST'])) {
		    write_log("API: Test command received.");
		    if (isset($_GET['apiToken'])) {
			    foreach ($GLOBALS['config'] as $section => $setting) {
				    if ($section != "general") {
					    $testToken = $setting['apiToken'];
					    if ($testToken == $_GET['apiToken']) {
						    echo 'success';
						    die();
					    }
				    }
			    }
		    }
		    echo 'token_not_recognized';
		    die();
	    }

	    // Fetches a list of clients
	    if (isset($_GET['clientList'])) {
		    write_log("API: Returning clientList");
		    $devices = fetchClientList(scanDevices());
		    echo $devices;
		    die();
	    }

	    if (isset($_GET['serverList'])) {
		    write_log("API: Returning serverList");
		    $devices = fetchServerList(scanDevices());
		    echo $devices;
		    die();
	    }

	    if (isset($_GET['fetchList'])) {
		    $fetch = $_GET['fetchList'];
		    write_log("API: Returning profile list for " . $fetch);
		    $list = fetchList($fetch);
		    echo $list;
		    die();
	    }

	    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		    write_log("This is an API.ai Request!!!!");
		    $json = file_get_contents('php://input');
		    $request = json_decode($json, true);
		    $request = array_filter_recursive($request);
		    parseApiCommand($request);
		    die();
	    }

	    if ((isset($_GET['say'])) && (isset($_GET['command']))) {
		    write_log("New play command caught");
		    $apiaikey = '65654f820d4647ab9cf7eddddbba6e02';
		    $_SESSION['counter2'] = (isset($_SESSION['counter2']) ? $_SESSION['counter2']++ : 0);
		    try {
			    $url = 'https://api.api.ai/v1/query?v=20150910&query=' . urlencode($_GET['command']) . '&lang=en&sessionId=' . $_SESSION['plexServerToken'] . $_SESSION['counter2'];
			    $response = curlGet($url, ['Authorization: Bearer ' . $apiaikey], 3);
			    if ($response == null) {
				    write_log("Null response received from API.ai, re-submitting.");
				    $response = curlGet($url, ['Authorization: Bearer ' . $apiaikey], 10);
			    }
			    write_log("Result: " . json_encode(json_decode($response, true)));
			    $request = json_decode($response, true);
			    $request = array_filter_recursive($request);
			    $request['originalRequest']['data']['inputs'][0]['raw_inputs'][0]['query'] = $request['result']['resolvedQuery'];
			    parseApiCommand($request);
			    die();
		    } catch (\Exception $error) {
			    write_log(json_encode($error->getMessage()));
		    }
	    }

	    // This tells the api to parse our command with the plex "play" parser
	    if (isset($_GET['play'])) {
		    if (isset($_GET['command'])) {
			    $command = cleanCommandString($_GET['command']);
			    write_log("################PARSEPLAY_START$###################################\r\n\r\n");
			    write_log('Got a request to play ' . $command);
			    $resultArray = parsePlayCommand($command);
			    $queryOut = array();
			    $queryOut['initialCommand'] = $command;
			    $queryOut['parsedCommand'] = $command;
			    if ($resultArray) {
				    $result = $resultArray[0];
				    $queryOut['mediaResult'] = $result;
				    $playResult = playMedia($result);
				    $searchType = $result['searchType'];
				    $type = (($searchType == '') ? $result['type'] : $searchType);
				    $queryOut['parsedCommand'] = 'Play the ' . $type . ' named ' . $command . '.';
				    $queryOut['playResult'] = $playResult;

				    if ($queryOut['mediaResult']['exact'] == 1) {
					    $queryOut['mediaStatus'] = "SUCCESS: Exact match found.";
				    } else {
					    $queryOut['mediaStatus'] = "SUCCESS: Approximate match found.";
				    }
			    } else {
				    $queryOut['mediaStatus'] = 'ERROR: No results found';
			    }
			    $queryOut['timestamp'] = timeStamp();
			    $queryOut['serverURI'] = $_SESSION['plexServerUri'];
			    $queryOut['serverToken'] = $_SESSION['plexServerToken'];
			    $queryOut['clientURI'] = $_SESSION['plexClientUri'];
			    $queryOut['clientName'] = $_SESSION['plexClientName'];
			    $queryOut['commandType'] = 'play';
			    $result = json_encode($queryOut);
			    header('Content-Type: application/json');
			    log_command($result);
			    echo $result;
			    die();
		    }
	    }


	    // This tells the api to parse our command with the plex "control" parser
	    if (isset($_GET['control'])) {
		    if (isset($_GET['command'])) {
			    $command = cleanCommandString($_GET['command']);
			    write_log('Got a control request: ' . $command);
			    $result = parseControlCommand($command);
			    $newCommand = json_decode($result, true);
			    $newCommand['timestamp'] = timeStamp();
			    $result = json_encode($newCommand);
			    header('Content-Type: application/json');
			    log_command($result);
			    echo $result;
			    die();
		    }
	    }

	    // This tells the api to parse our command with the "fetch" parser
	    if (isset($_GET['fetch'])) {
		    if (isset($_GET['command'])) {
			    $command = cleanCommandString($_GET['command']);
			    $result = parseFetchCommand($command);
			    $result['commandType'] = 'fetch';
			    $result['timestamp'] = timeStamp();
			    log_command(json_encode($result));
			    header('Content-Type: application/json');
			    echo json_encode($result);
			    die();
		    }
	    }
    }



	/*

	DO NOT SET ANY SESSION VARIABLES UNTIL THIS IS CALLED HERE

	*/
	function setSessionVariables() {
		$_SESSION['mc'] = initMCurl();
		$_SESSION['deviceID'] = checkSetDeviceID();

		$ip = $GLOBALS['config']->get('user-_-'.$_SESSION['plexUserName'],'publicAddress', false);
		if (!($ip)) {
			$ip = curlGet('https://plex.tv/pms/:/ip');
			$ip = serverProtocol() . $ip . '/Phlex';
			$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'publicAddress', $ip);
			saveConfig($GLOBALS['config']);
		}
		$devices = $GLOBALS['config']->get('user-_-'.$_SESSION['plexUserName'], 'dlist', false);
		if ($devices) $_SESSION['list_plexdevices'] = json_decode(base64_decode($devices),true);
        $devices = scanDevices();
		// See if we have a server saved in settings
		$_SESSION['plexServerId'] = $GLOBALS['config']->get('user-_-'.$_SESSION['plexUserName'], 'plexServerId', false);
		if (!($_SESSION['plexServerId'])) {
			// If no server, fetch a list of them and select the first one.
			write_log('No server selected, fetching first avaialable device.',"INFO");
			$servers = $devices['servers'];
			if ($servers) {
				$server = $servers[0];
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexServerId',$server['id']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexServerProduct',$server['product']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexServerName',$server['name']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexServerUri',$server['uri']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexServerPublicUri',$server['publicUri']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexServerPublicAddress',$server['publicAddress']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexServerToken',$server['token']);
				fetchSections();
				saveConfig($GLOBALS['config']);
			}
		}
		// Now check and set up our client, just like we did with the server

		$_SESSION['plexClientId'] = $GLOBALS['config']->get('user-_-'.$_SESSION['plexUserName'], 'plexClientId', false);
		if (!($_SESSION['plexClientId'])) {
			write_log("No client selected, fetching first available device.","INFO");
            $clients = $devices['clients'];
			if ($clients) {
				$client = $clients[0];
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexClientId',$client['id']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexClientProduct',$client['product']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexClientName',$client['name']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexClientUri',$client['uri']);
				saveConfig($GLOBALS['config']);
			}
		}

		$_SESSION['plexDvrId'] = $GLOBALS['config']->get('user-_-'.$_SESSION['plexUserName'], 'plexDvrId', false);
		if (!($_SESSION['plexDvrId'])) {
			write_log("No DVR found, checking for available devices.","INFO");
			$dvrs = $devices['dvrs'] ?? [];
			if (count($dvrs) >= 1) {
			    $dvr = $dvrs[0];
                write_log("DVR found: ".json_encode($dvr),"INFO");
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'], 'plexDvrId',$dvr['id']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexDvrProduct',$dvr['product']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexDvrName',$dvr['name']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexDvrUri',$dvr['uri']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexDvrPublicUri',$dvr['publicAddress']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexDvrToken',$dvr['token']);
				saveConfig($GLOBALS['config']);
			}
		}

		checkSetApiToken($_SESSION['plexUserName']);
		$userSections = $GLOBALS['config']->getSection('user-_-'.$_SESSION['plexUserName'],false);

		foreach ($userSections as $key=>$value) {
			$_SESSION[$key] = $value;
		}

		foreach ($_SESSION as $key=>$value) {
			if (preg_match("/ip_/",$key)) {
				if (!isset(parse_url($value)['scheme'])) {
					$_SESSION[$key] = 'http://' . parse_url($value)['path'] ?? 'http://localhost';
					write_log("URL Does not have a specified protocol, setting " . $key . ": " . $_SESSION[$key]);
				}
			}
		}

		$defaults = ['returnItems'=>'6', 'rescanTime'=>'6', 'couchIP'=>'http://localhost', 'ombiIP'=>'http://localhost', 'sonarrIP'=>'http://localhost', 'sickIP'=>'http://localhost', 'radarrIP'=>'http://localhost', 'couchPort'=>'5050', 'ombiPort'=>'3579', 'sonarrPort'=>'8989', 'sickPort'=>'8083', 'radarrPort'=>'7878', 'apiClientToken'=>'', 'apiDevToken'=>'', 'dvr_resolution'=>'0', 'plexDvrNewAirings'=>'true','plexDvrStartOffset'=>'2','plexDvrEndOffset'=>'2','plexDvrResolution'=>'0'];
		foreach ($defaults as $key=>$value) {
			if (! isset($_SESSION[$key])) $_SESSION[$key] = $value;
		}


		// Reload section UUID's
		if ($_SESSION['plexServerUri']) fetchSections();

		$_SESSION['plexHeader'] = '&X-Plex-Product=Phlex'.
			'&X-Plex-Version=1.0.0'.
			'&X-Plex-Client-Identifier='.$_SESSION['deviceID'].
			'&X-Plex-Platform=Web'.
			'&X-Plex-Platform-Version=1.0.0'.
			'&X-Plex-Device=PhlexWeb'.
			'&X-Plex-Device-Name=Phlex'.
			'&X-Plex-Device-Screen-Resolution=1520x707,1680x1050,1920x1080'.
			'&X-Plex-Token='.$_SESSION['plexToken'];

		// Q&D Variable with the plex target client header
		$_SESSION['plexClientHeader']='&X-Plex-Target-Client-Identifier='.$_SESSION['plexClientId'];
	}

	// Log our current session variables

	function sessionData() {
		write_log("---------------------------------------------------------------------------------");
		write_log("-------------Session Variables----------");
		write_log("DeviceID: ".$_SESSION['deviceID']);
		write_log("Username: ".$_SESSION['plexUserName']);
		write_log("----------------------------------------");
		write_log("Server Name: ".$_SESSION['plexServerName']);
		write_log("Server ID: ".$_SESSION['plexServerId']);
		write_log("Server URI: ".$_SESSION['plexServerUri']);
		write_log("Server Public Address: ".$_SESSION['plexServerPublicUri']);
		write_log("Server Token: ".(isset($_SESSION['plexServerToken']) ? "Valid": "ERROR"));
		write_log("----------------------------------------");
		write_log("Client Name: ".$_SESSION['plexClientName']);
		write_log("Client ID: ".$_SESSION['plexClientId']);
		write_log("Client URI: ".$_SESSION['plexClientUri']);
		write_log("Client Product: ".$_SESSION['plexClientProduct']);
		write_log("----------------------------------------");
		write_log("Plex DVR Enabled: ".($_SESSION['plexDvrUri'] ? "true" : "false"));
		write_log("----------------------------------------");
		write_log("CouchPotato Enabled: ".$_SESSION['couchEnabled']);
		write_log("Ombi Enabled: ".$_SESSION['ombiEnabled']);
		write_log("Radarr Enabled: ".$_SESSION['radarrEnabled']);
		write_log("Sonarr Enabled: ".$_SESSION['sonarrEnabled']);
		write_log("Sick Enabled: ".$_SESSION['sickEnabled']);
		write_log("----------------------------------------");
		write_log("Clean Logs: ".$_SESSION['cleanLogs']);
		write_log("Cast Enabled: ".$_SESSION['useCast']);
	}


	/* This is our handler for fetch commands

	You can either say just the name of the show or series you want to fetch,
	or explicitely state "the movie" or "the show" or "the series" to specify which one.

	If no media type is specified, a search will first be executed for a movie, and then a
	show, with the first found result being added.

	If a searcher is not enabled in settings, nothing will happen and an appropriate status
	message should be returned as the 'status' value of our object.

	*/


	function parseFetchCommand($command,$type=false) {
		$resultOut = array();
		write_log("Function Fired.");
        $episode = $remove = $season = $useNext = false;
        //Sanitize our string and try to rule out synonyms for commands
		$result['initialCommand'] = $command;
		$commandArray = explode(' ',$command);
		if (arrayContains('movie',$commandArray)) {
			$commandArray = array_diff($commandArray,array('movie'));
			$type = 'movie';
		}
		if (arrayContains('show',$commandArray) || arrayContains('series',$commandArray)) {
			$commandArray = array_diff($commandArray,array('show','series'));
			$type = 'show';
		}
		if (arrayContains('season',$commandArray)) {
			write_log("Found the word season.");
			foreach($commandArray as $word) {
				if ($useNext) {
					$season = intVal($word);
					break;
				}
				if ($word == 'season') {
					$useNext = true;
				}

			}
			if ($season) {
				$type = 'show';
				$commandArray = array_diff($commandArray,array('season',$season));
			}
		}
		$useNext = false;
		if (arrayContains('episode',$commandArray)) {
			write_log("Found the word episode.");
			foreach($commandArray as $word) {
				if ($useNext) {
					$episode = intVal($word);
					break;
				}
				if ($word == 'episode') {
					$useNext = true;
				}
				if (($word == 'latest') || ($word == 'new') || ($word == 'newest')) {
					$remove = $word;
					$episode = -1;
					break;
				}
			}
			if ($episode) {
				$type = 'show';
				$commandArray = array_diff($commandArray,array('episode',$episode));
				if (($episode == -1) && ($remove)) $commandArray = array_diff($commandArray,array('episode',$remove));
			}
		}
		if ($type == false) $resultOut['parsedCommand'] = 'Fetch the first movie or show named '.implode(" ",$commandArray);
		switch ($type) {
			case 'show':
			write_log("Searching explicitely for a show.");
				if ($_SESSION['sonarrEnabled'] || $_SESSION['sickEnabled']) {
					$result = downloadSeries(implode(" ",$commandArray),$season,$episode);
					$resultTitle = $result['mediaResult']['title'];
					$resultOut['parsedCommand'] = 'Fetch '.($season ? 'Season '.$season.' of ' : '').($episode ? 'Episode '.$episode.' of ' : '').'the show named '.$resultTitle;
					write_log("Result ".json_encode($result));

				} else {
					$result['status'] = 'ERROR: No fetcher configured for ' .$type.'.';
					write_log("". $result['status']);
				}
				break;
			case 'movie':
				write_log("Searching explicitely for a movie.");
				if (($_SESSION['couchEnabled']) || ($_SESSION['ombiEnabled']) || ($_SESSION['radarrEnabled'])) {
					$result = downloadMovie(implode(" ",$commandArray));
				} else {
					$result['status'] = 'ERROR: No fetcher configured for ' .$type.'.';
					write_log("". $result['status']);
				}
				break;
			default:
				if (($_SESSION['couchEnabled']) || ($_SESSION['radarrEnabled'])) {
					write_log("Searching for first media matching title, starting with movies.");
					$result = downloadMovie(implode(" ", $commandArray));
				}

				if ((preg_match("/ERROR/",$result['status'])) && (($_SESSION['sonarrEnabled']) || ($_SESSION['sickEnabled']))) {
					write_log("No results for transient search as movie, searching for show.");
					$result = downloadSeries(implode(" ", $commandArray));
					break;
				}
				if (preg_match("/ERROR/",$result['status'])) {
					$result['status'] = 'ERROR: No results found or no fetcher configured.';
					write_log("". $result['status']);
				}
				break;
		}
		$result['mediaStatus'] = $result['status'];
		$result['parsedCommand'] = $resultOut['parsedCommand'];
		$result['initialCommand'] = $command;
		write_log("Final result: ".json_encode($result));
		return $result;
	}


	function parseControlCommand($command) {
		write_log("Function Fired.");
		//Sanitize our string and try to rule out synonyms for commands
		$queryOut['initialCommand'] = $command;
		write_log("Initial command is ".$command);
		$command = str_replace("resume","play",$command);
		$command = str_replace("jump","skip",$command);
		$command = str_replace("go","skip",$command);
		$command = str_replace("seek","step",$command);
		$command = str_replace("ahead","forward",$command);
		$command = str_replace("backward","back",$command);
		$command = str_replace("rewind","seek back",$command);
		$command = str_replace("fast", "seek",$command);
		$command = str_replace("skip forward","skipNext",$command);
		$command = str_replace("fast forward","stepForward",$command);
		$command = str_replace("seek forward","stepForward",$command);
		$command = str_replace("seek back","stepBack",$command);
		$command = str_replace("skip back","skipPrevious",$command);
		$adjust = $cmd = false;
		write_log("Fixed command is ".$command);
		$queryOut['parsedCommand'] = "";
		$commandArray = array("play","pause","stop","skipNext","stepForward","stepBack","skipPrevious","volume");
		if (strpos($command,"volume")) {
			write_log("Should be a volume command: ".$int);
				$int = filter_var($command, FILTER_SANITIZE_NUMBER_INT);
				if (! $int) {
					if (preg_match("/UP/",$command)) {
						$adjust = true;
						$int = 10;
					}

					if (preg_match("/DOWN/",$command)) {
						$adjust = true;
						$int = -10;
					}
					if ($adjust) {
						write_log("This should be an adjust command");
						$status = playerStatus();
						$status = json_decode($status,true);
						$type = $status['type'] ?? false;
						write_log("Status: ".json_encode($status));
						$volume = $status['volume'];
						if ($volume) {
							if ($type) $volume = $volume * 100;
							$int = $volume + $int;
							if ($type) $int = $int/100;
							write_log("Old volume should be ".$volume);
							write_log("New volume should be ".$int);
						}
					}
				}
				$queryOut['parsedCommand'] .= "Set the volume to " . $int . " percent.";
				$cmd = 'setParameters?volume='.$int;
		}

		if (preg_match("/subtitles/",$command)) {
			write_log("Fixing subtitle Command.");
            $streamID = 0;
			if (preg_match("/on/",$command)) {
				$status = playerStatus();
				write_log("Got Player Status: ".$status);
				$statusArray = json_decode($status,true);
				$streams = $statusArray['mediaResult']['Media']['Part']['Stream'];
				foreach ($streams as $stream) {
					$type = $stream['@attributes']['streamType'];
					if ($type == 3) {
						write_log("Got me a subtitle.");
						$code = $stream['@attributes']['languageCode'];
						if (preg_match("/eng/",$code)) {
							$streamID = $stream['@attributes']['id'];
						}
					}
				}
			}
            $cmd = 'setStreams?subtitleStreamID='.$streamID;
		}

		if (! $cmd ) {
			write_log("No command set so far, making one.");
				$cmds = explode(" ",$command);
				$newString = array_intersect($commandArray,$cmds);
				write_log("New String is ".json_encode($newString)." cmdarray is ".print_r($cmds,true));
                $result = implode(" ",$newString);
                write_log("Result is ".$result);
                if ($result) {
                    $cmd = $queryOut['parsedCommand'] .= $cmd = $result;
                }
		}
		if ($cmd) {
			write_log("Sending a command of ".$cmd);
			$result = sendCommand($cmd);
			$results['url'] = $result['url'];
			$results['status'] = $result['status'];
			$queryOut['playResult'] = $results;
			$queryOut['mediaStatus'] = 'SUCCESS: Not a media command';
			$queryOut['commandType'] = 'control';
			$queryOut['clientURI'] = $_SESSION['plexClientUri'];
			$queryOut['clientName'] = $_SESSION['plexClientName'];
			return json_encode($queryOut);
		}
		return false;

	}


	function parseRecordCommand($command) {
		write_log("Function fired.");
		$url = $_SESSION['plexDvrUri'].'/tv.plex.providers.epg.onconnect:4/hubs/search?sectionId=&query='.urlencode($command).'&X-Plex-Token='.$_SESSION['plexDvrToken'];
		write_log("Url is: ".$url);
		$result = curlGet($url);
		if ($result) {
			write_log("Result is ".$result);
			$container = new SimpleXMLElement($result);
			$result = false;
            if (isset($container->Hub)) {
                foreach($container->Hub as $hub) {
                    $array = json_decode(json_encode($hub),true);
                    $type = (string) $array['@attributes']['type'];
                    if (($type == 'show') || ($type == 'movie')) {
                        $title = $array['Directory']['@attributes']['title'];
                        if (similarity(cleanCommandString($title), $command) >=.7) {
                            write_log("We have a match, proceeding: ".$command);
                            $result = $array;
                        }
                    }
                }
            }
			if ($result) {
				unset($array);
				$array = $result;
				$url = $_SESSION['plexServerUri'];
				$guid = $array['Directory']['@attributes']['guid'];
				$params = array(
					"guid"=>$guid
				);
				$url.= '/media/subscriptions/template?'.http_build_query($params).'&X-Plex-Token='.$_SESSION['plexServerToken'];
				write_log("URL is ".$url);
				$template = curlGet($url);
				if (! $template) {
					write_log("Error fetching download template, aborting.","ERROR");
					return false;
				}
				$container = new SimpleXMLElement($template);
				$container = json_decode(json_encode($container),true);
				$paramString = $container['SubscriptionTemplate']['MediaSubscription']['@attributes']['parameters'];
				unset($params);
				unset($url);
				$year = $array['Directory']['@attributes']['year'];
				$thumb = $array['Directory']['@attributes']['thumb'];
				$url=$_SESSION['plexServerUri'].'/media/subscriptions?';
				$params = array();
				$prefs = array();
				//These need to be put into settings at some point in time
				$prefs['onlyNewAirings']=$_SESSION['plexDvrNewAirings'];
				$prefs['minVideoQuality']=$_SESSION['plexDvrResolution'];
				$prefs['replaceLowerQuality']=$_SESSION['plexDvrRelaceLower'];
				$prefs['recordPartials']=$_SESSION['plexDvrRecordPartials'];
				$prefs['startOffsetMinutes']=$_SESSION['plexDvrStartOffset'];
				$prefs['endOffsetMinutes']=$_SESSION['plexDvrEndOffset'];
				$prefs['lineupChannel']='';
				$prefs['startTimeslot']=-1;
				$prefs['oneShot']="true";
				$prefs['autoDeletionItemPolicyUnwatchedLibrary']=0;
				$prefs['autoDeletionItemPolicyWatchedLibrary']=0;
				$params['prefs'] = $prefs;
				$sectionId = $array['Directory']['@attributes']['librarySectionID'];
				$title = $array['Directory']['@attributes']['title'];
				$params['targetLibrarySectionID']= $sectionId;
				$params['targetSectionLocationID']= $sectionId;
				$params['includeGrabs'] = 1;
				$params['type'] = $sectionId;
				$url .= http_build_query($params).'&'.$paramString.'&X-Plex-Token='.$_SESSION['plexServerToken'];
				$result = curlPost($url);
				if ($result) {
					$container = new SimpleXMLElement($result);
                    if (isset($container->MediaSubscription)) {
                        foreach ($container->MediaSubscription as $subscription) {
                            $show = json_decode(json_encode($subscription),true);
                            $added = $show['Directory']['@attributes']['title'];
                            if (cleanCommandString($title) == cleanCommandString($added)) {
                                write_log("Show added to record successfully: ".json_encode($show));
                                $return = array(
                                    "title"=>$added,
                                    "year"=>$year,
                                    "type"=>$show['Directory']['@attributes']['type'],
                                    "thumb"=>$thumb,
                                    "art"=>$thumb,
                                    "url"=>$_SESSION['plexServerUri'].'/subscriptions/'.$subscription['@attributes']['key'].'?X-Plex-Token='.$_SESSION['plexServerToken']
                                );
                                return $return;
                            }
                        }
                    }
				}
			}
		}
		return false;
	}

	// This is now our one and only handler for searches.
	function parsePlayCommand($command,$year=false,$artist=false,$type=false) {
		write_log("################parsePlayCommand_START$##########################");
		write_log("Function Fired.");
		write_log("Initial command - ".$command);
		$playerIn = false;
		$commandArray = explode(" "	,$command);
		// An array of words which don't do us any good
		// Adding the apostrophe and 's' are necessary for the movie "Daddy's Home", which Google Inexplicably returns as "Daddy ' s Home"
		$stripIn = array("of","the","an","a","at","th","nd","in","it","from","'","s","and");

		// An array of words that indicate what kind of media we'd like
		$mediaIn = array("season","series","show","episode","movie","film","beginning","rest","end","minutes","minute","hours","hour","seconds","second");

		// An array of words that would modify or filter our search
		$filterIn = array("genre","year","actor","director","directed","starring","featuring","with","made","created","released","filmed");

		// An array of words that would indicate which specific episode or media we want
		$numberWordIn = array("first","pilot","second","third","last","final","latest","random");

		foreach($_SESSION['list_plexdevices']['clients'] as $client) {
			$clientName = '/'.strtolower($client['name']).'/';
			if (preg_match($clientName,$command)) {
				write_log("I was just asked me to play something on a specific device: ".$client['name']);
				$playerIn = explode(" ",cleanCommandString($client['name']));
				array_push($playerIn,"on","in");
				$_SESSION['plexClientId'] = $client['id'];
				$_SESSION['plexClientName'] = $client['name'];
				$_SESSION['plexClientUri'] = $client['uri'];
				$_SESSION['plexClientProduct'] = $client['product'];
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexClientId',$client['id']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexClientProduct',$client['product']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexClientName',$client['name']);
				$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'plexClientUri',$client['uri']);
				saveConfig($GLOBALS['config']);
			}
		}
		if (isset($_SESSION['cleaned_search'])) unset($_SESSION['cleaned_search']);

		if ($playerIn) {
			$commandArray = array_diff($commandArray,$playerIn);
			$_SESSION['cleaned_search'] = ucwords(implode(" ",$commandArray));
		}

		// An array of words from our command that are numeric
		$numberIn=array();
		foreach($commandArray as $number) {
			if ((is_numeric($number)) || in_array($number,$numberWordIn)) {
				array_push($numberIn,$number);
			}
		}

		// Create arrays of values we need to evaluate
		$stripOut = array_intersect($commandArray,$stripIn);
		$mediaOut = array_intersect($commandArray,$mediaIn);
		$filterOut = array_intersect($commandArray,$filterIn);
		$numberOut = array_intersect($commandArray,$numberIn);

		if ($year) {
			array_push($mediaOut,'year');
			array_push($numberOut,$year);
		}

		$mods = array();
		$mods['num'] = array();
		$mods['filter'] = array();
		$mods['media'] = array();

		if ($stripOut) {
			$commandArray = array_diff($commandArray, $stripOut);
			write_log("stripOut: ".implode(" : ",$stripOut));
		}

		if ($filterOut) {
			$commandArray = array_diff($commandArray, $filterOut);
			//					 "genre","year","actor","director","directed","starring","featuring","with","made","created","released","filmed"
			$replaceArray = array("","","actor","director","director","actor","actor","actor","year","year","year","year");
			$filterOut = str_replace($filterIn,$replaceArray,$filterOut);
			$mods['filter']=$filterOut;
			write_log("filterOut: ".implode(" : ",$mods['filterOut']));
		}

		if ($mediaOut) {
			$commandArray = array_diff($commandArray, $mediaOut);
			//					  "season","series","show","episode","movie","film","beginning","rest","end","minute","minutes","hour","hours"
			$replaceArray = array("season","season","show","episode","movie","movie","0","-1","-1","mm","mm","hh","hh","ss","ss");
			write_log("mediaOut: ".implode(" : ",$mediaOut));
			$mediaOut=str_replace($mediaIn,$replaceArray,$mediaOut);
			foreach($mediaOut as $media) {
				if (is_numeric($media)) {
					$mediaOut = array_diff($mediaOut,array($media));
					array_push($mediaOut,"offset");
					array_push($numberOut,$media);
				}
			}
			$mods['media'] = $mediaOut;
		}
		$mods['preFilter'] = implode(" ",$commandArray);
		if ($numberOut) {
			$commandArray = array_diff($commandArray, $numberOut);
			// "first","pilot","second","third","last","final","latest","random"
			$replaceArray = array(1,1,2,3,-1,-1,-1,-2);
			$mods['num']=str_replace($numberWordIn,$replaceArray,$numberOut);
			write_log("numberOut: ".implode(" : ",$mods['num']));
		}

		if((empty($commandArray)) && (count($mods['num']) > count($mods['media']))) {
			array_push($commandArray,$mods['num'][count($mods['num'])-1]);
			unset($mods['num'][count($mods['num'])-1]);
		}
		write_log("Resulting string is:".implode(" : ",$commandArray));
		$mods['target']=implode(" ",$commandArray);
		if ($artist) $mods['artist']=$artist;
        if ($type) $mods['type']=$type;
		$result = fetchInfo($mods); // Returns false if nothing found
		return $result;
	}


	// Parse and handle API.ai commands
	function parseApiCommand($request) {
		write_log("Function fired.");
		$greeting = $mediaResult = $screen = $year = false;
		$card = $suggestions = false;
		write_log("Full request text is ".json_encode($request));
		$result = $request["result"];
		$action = $result['parameters']["action"] ?? false;
		$command = $result["parameters"]["command"] ?? false;
		$control = $result["parameters"]["Controls"] ?? false;
		$year = $request["result"]["parameters"]["age"]["amount"] ?? false;
		$type = $result['parameters']['type'] ?? false;
		$days = $result['parameters']['days'] ?? false;
		$artist = $result['parameters']['artist'] ?? false;

		if ($command) $command = cleanCommandString($command);
		if ($control) $control = strtolower($control);

		$capabilities = $request['originalRequest']['data']['surface']['capabilities'];
        $GLOBALS['screen'] = false;
		foreach ($capabilities as $capability) {
            if ($capability['name'] == "actions.capability.SCREEN_OUTPUT") $GLOBALS['screen'] = true;
        }

		$rawspeech = (string)$request['originalRequest']['data']['inputs'][0]['raw_inputs'][0]['query'];
		write_log("Raw speech is ".$rawspeech);
		$queryOut=array();
		$queryOut['serverURI'] = $_SESSION['plexServerUri'];
		$queryOut['serverToken'] = $_SESSION['plexServerToken'];
		$queryOut['clientURI'] = $_SESSION['plexClientUri'];
		$queryOut['clientName'] = $_SESSION['plexClientName'];
		$queryOut['initialCommand'] = $rawspeech;
		$queryOut['timestamp'] = timeStamp();
		write_log("Action is currently ".$action);
		$contexts=$result["contexts"];
		foreach($contexts as $context) {
			if (($context['name'] == 'promptfortitle') && ($action=='') && ($control=='') && ($command=='')) {
				$action = 'play';
				write_log("This is a response to a title query.");
				if (!($command)) $command = cleanCommandString($result['resolvedQuery']);
				if ($command == 'googleassistantwelcome') {
					$action = $command = false;
					$greeting = true;
				}
			}

			if ((cleanCommandString($rawspeech) == 'talk to flex tv') && (! $greeting)) {
			    write_log("Fixing duplicate talk to request");
			    $action = $command = false;
                $greeting = true;
            }

            if (($artist) && (! $command)) {
			    $command = $artist;
			    $artist = false;
            }

			if (($control == 'play') && ($action == '') && (! $command == '')) {
				$action = 'play';
				$control = false;
			}

            if (($command == '') && ($control == '') && ($action == 'play') && ($type == '')) {
                $action = 'control';
                $command = 'play';
            }

			if (($context['name'] == 'yes') && ($action=='fetchAPI')) {
				write_log("Context JSON should be ".json_encode($context));
				$command = (string)$context['parameters']['command'];
				$type = (isset($context['parameters']['type']) ? (string) $context['parameters']['type'] : false);
				$command = cleanCommandString($command);
				$playerIn = false;
                foreach($_SESSION['list_plexdevices']['clients'] as $client) {
                    $clientName = '/'.strtolower($client['name']).'/';
                    if (preg_match($clientName,$command)) {
                        write_log("Re-removing device name from fetch search: ".$client['name']);
                        $playerIn = explode(" ",cleanCommandString($client['name']));
                        array_push($playerIn,"on","in");
                    }
                }
                if (isset($_SESSION['cleaned_search'])) unset($_SESSION['cleaned_search']);

                if ($playerIn) {
                	$commandArray = explode(" ",$command);
                    $commandArray = array_diff($commandArray,$playerIn);
                    $command = ucwords(implode(" ",$commandArray));
                }
			}
			if (($context['name'] == 'google_assistant_welcome') && ($action == '') && ($command == '') && ($control == ''))  {
				write_log("Looks like the default intent, we should say hello.");
				$greeting = true;
			}
		}

		if ($action == 'changeDevice') {
			$command = $request['result']['parameters']['player'];
			write_log("Got a player name: ".$command);
		}

		if ($control == 'skip forward') {
			write_log("Action should be changed now.");
			$action ='control';
			$command = 'skip forward';
		}

		if ($control == 'skip backward') {
			write_log("Action should be changed now.");
			$action ='control';
			$command = 'skip backward';
		}

		if (preg_match("/subtitles/",$control)) {
			write_log("Subtitles?");
			$action = 'control';
			$command = str_replace(' ', '', $control);
		}

		write_log("Final params should be an action of ".$action.", a command of ".$command.", a type of ".$type.", and a control of ".$control.".");

		// This value tells API.ai that we are done talking.  We set it to a positive value if we need to ask more questions/get more input.
		$contextName = "yes";
		$queryOut['commandType'] = $action;
		$resultData = array();

		if ($greeting) {
			$greetings = array("Hi, I'm Flex TV.  What can I do for you today?","Greetings! How can I help you?","Hello there. Try asking me to play a movie or show.'");
			$speech = $greetings[array_rand($greetings)];
			$contextName = 'PlayMedia';
			//$linkout = ['destinationName'=>'View Readme','url'=>'https://github
			//.com/d8ahazard/Phlex/blob/master/readme.md'];
			//$button = [['title'=>'View Readme','openUrlAction'=>['url'=>'https://github.com/d8ahazard/Phlex/blob/master/readme.md']]];
			//$card = [['title'=>"Welcome to Flex TV!",'formattedText'=>'','image'=>['url'=>'https://phlexchat.com/img/avatar.png','accessibilityText'=>'Phlex logo image'],'buttons'=>$button]];
			$card = [['title'=>"Welcome to Flex TV!",'image'=>['url'=>'https://phlexchat.com/img/avatar.png'],'subtitle'=>'']];
            $queryOut['card'] = $card;
            $queryOut['speech'] = $speech;
			returnSpeech($speech,$contextName,$card,true,false);
			log_command(json_encode($queryOut));
			unset($_SESSION['deviceArray']);
			die();
		}

		if (($action == 'record') && ($command)) {
			write_log("Got a record command.");
			$contextName = 'waitforplayer';
			if($_SESSION['plexDvrUri']) {
				$result = parseRecordCommand($command);
				if($result) {
					$title = $result['title'];
					$year = $result['year'];
					$type = $result['type'];
					$queryOut['parsedCommand'] = 'Add the '.$type.' named '.$title.' ('.$year.') to the recording schedule.';
					$speech = "Hey, look at that.  I've added the ".$type." named ".$title." (".$year.") to the recording schedule.";
					$card = [['title'=>$title,'image'=>['url'=>$result['thumb']],'subtitle'=>'']];
					$results['url'] = $result['url'];
					$results['status'] = "Success.";
					$queryOut['mediaResult'] = $result;
					$queryOut['card'] = $card;
					$queryOut['mediaStatus'] = 'SUCCESS: Not a media command';
					$queryOut['commandType'] = 'dvr';
				} else {
					$queryOut['parsedCommand'] = 'Add the media named '.$command;
					$speech = "I wasn't able to find any results in the episode guide that match '".ucwords($command)."'.";
					$results['url'] = $result['url'];
					$card = false;
					$results['status'] = "No results.";
				}
			} else {
				$speech = "I'm sorry, but I didn't find any instances of Plex DVR to use.";
				$card = false;
			}
			returnSpeech($speech,$contextName,$card);
			$queryOut['speech'] = $speech;
			log_command(json_encode($queryOut));
			die();

		}

		if (($action == 'changeDevice') && ($command)) {
			$list = $_SESSION['deviceArray'];
            $type = $_SESSION['type'];
            write_log("Session type is ".$type);
            if (isset($list) && isset($type)) {
                $typeString = (($type == 'player') ? 'client' : 'server');
                $score = 0;
                foreach ($list as $device) {
                    $value = similarity(cleanCommandString($device['name']), cleanCommandString($command));
                    if (($value >= .7) && ($value >= $score)) {
                        write_log("Got a winner: " . $device['name']);
                        $result = $device;
                        $score = $value;
                    }
                }
                write_log("Result should be " . json_encode($result));
                if ($result) {
                    $speech = "Okay, I've switched the " . $typeString . " to " . $command . ".";
                    $contextName = 'waitforplayer';
                    returnSpeech($speech, $contextName);
                    write_log("Still alive.");
                    $name = (($result['product'] == 'Plex Media Server') ? 'plexServerId' : 'plexClientId');
                    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $name, $result['id']);
                    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $name . 'Uri', $result['uri']);
                    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $name . 'Name', $result['name']);
                    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $name . 'Product', $result['product']);
                    $GLOBALS['config']->set('user-_-' . $_SESSION['plexUserName'], $name . 'Token', $result['token']);
                    saveConfig($GLOBALS['config']);
                    setSessionVariables();
                    $queryOut['playResult']['status'] = 'SUCCESS: ' . $typeString . ' changed to ' . $command . '.';
                } else {
                    $speech = "I'm sorry, but I couldn't find " . $command . " in the device list.";
                    $contextName = 'waitforplayer';
                    returnSpeech($speech, $contextName);
                    $queryOut['playResult']['status'] = 'ERROR: No device to select.';
                }
                $queryOut['parsedCommand'] = "Change ".$typeString." to " . $command . ".";
                $queryOut['speech'] = $speech;
                $queryOut['mediaStatus'] = "Not a media command.";
                write_log("Forcing refresh now for ".$typeString);
                log_command(json_encode($queryOut));
                unset($_SESSION['deviceArray']);
                unset($_SESSION['type']);
                die();
            } else write_log("No list or type to pick from.");
		}

		if ($action == 'status') {
				$status = playerStatus();
				write_log("Raw status ".$status);
				$status = json_decode($status,true);
				write_log("Status is ".$status['status']);
				if ($status['status'] == 'playing') {
					$type = $status['mediaResult']['type'];
					$player = $_SESSION['plexClientName'];
					$thumb = $status['mediaResult']['art'];
					$title = $status['mediaResult']['title'];
                    $summary = $status['mediaResult']['summary'];
                    $tagline = $status['mediaResult']['tagline'];
                    $speech = "Currently, the ".$type." ".$title." is playing on ".$player.".";
					if ($type == 'episode') {
						$showTitle = $status['mediaResult']['grandparentTitle'];
						$epNum = $status['mediaResult']['index'];
						$seasonNum = $status['mediaResult']['parentIndex'];
						$speech = "Currently, Season ".$seasonNum." episode ". $epNum. " of ".$showTitle." is playing. This episode is named ".$title.".";
					}

					if ($type == 'track') {
					    $songtitle = $title;
					    $artist = $status['mediaResult']['grandparentTitle'];
					    $album = $status['mediaResult']['parentTitle'];
					    $year = $status['mediaResult']['year'];
                        $speech = "It looks like you're listening to ".$songtitle. ' by '.$artist. ' from the album '.$album . '.';
                        $title = $artist . ' - '.$songtitle;
					    $tagline = $album. ' ('.$year.')';

                    }

					$card = [["title"=>$title,"subtitle"=>$tagline,"formatted_text"=>$summary,'image'=>['url'=>$thumb]]];
                    $queryOut['card'] = $card;
				} else {
					$speech = "It doesn't look like there's anything playing right now.";
				}
				$contextName = 'PlayMedia';
				returnSpeech($speech,$contextName,$card);
				$queryOut['parsedCommand'] = "Report player status";
				$queryOut['speech'] = $speech;
				$queryOut['mediaStatus'] = "Success: Player status retrieved";
				$queryOut['mediaResult'] = $status['mediaResult'];
				log_command(json_encode($queryOut));
				unset($_SESSION['deviceArray']);
			die();
		}

		if (($action == 'recent') || ($action == 'ondeck')) {
			$type = $request["result"]['parameters']["type"];
			$list = (($action =='recent') ? fetchHubList($action,$type) : fetchHubList($action));
			$cards = false;
			if ($list) {
				write_log("Got me some results: ".$list);
				$array = json_decode($list,true);
				$speech = (($action=='recent')? "Here's a list of recent ".$type."s: " : "Here's a list of on deck items: ");
				$i = 1;
				$count = count($array);
                $cards = [];
                write_log("Item count: ".$count);
				foreach($array as $result) {
					$title = $result['title'];
					$showTitle = $result['grandparentTitle'];
                    $summary = $result['tagline'] ?? $result['summary'];
                    $thumb = $result['art'];
                    $type = trim($result['type']);
					write_log("Media item ".$title." is a ".$type);
					if ($type == 'episode') {
						write_log("This is a show, appending show title.");
						write_log($showTitle);
						$title = $showTitle.": ".$title;
					}
                    $item = ["title"=>$title,"summary"=>$summary,'image'=>['url'=>$thumb],"command"=>"play ".$result['title']];
                    array_push($cards,$item);
                    if (($i == $count) && ($count >=2)) {
						$speech .= "and ". $title.".";
					} else {
						$speech .= $title.", ";
					}
					$i++;
				}
				$queryOut['card'] = $cards;
				$queryOut['mediaStatus'] = 'SUCCESS: Hub array returned';
				$queryOut['mediaResult'] = $array[0];

            } else {
                write_log("Error fetching hub list.","ERROR");
				$queryOut['mediaStatus'] = "ERROR: Could not fetch hub list.";
				$speech = "Unfortunately, I wasn't able to find any results for that.  Please try again later.";
			}


            $contextName = 'promptfortitle';
			returnSpeech($speech,$contextName,$cards,true);
			$queryOut['parsedCommand'] = "Return a list of ".$action.' '.(($action == 'recent') ? $type : 'items').'.';
			$queryOut['speech'] = $speech;
			log_command(json_encode($queryOut));
			unset($_SESSION['deviceArray']);
			die();
		}

		if ($action =='upcoming') {
			$queryOut['parsedCommand'] = $rawspeech;
			write_log("Got an upcoming request here.");
			$list = fetchAirings($days);
			if ($list) {
				$i = 1;
				$speech = "Here's a list of scheduled recordings: ";
				if ($days == 'now') {
					$time = date('H');
					$verb = 'Today';
					if ($time >= 12) $verb = 'This afternoon';
					if ($time >= 5) $verb = 'Tonight';
					$speech = $verb . ", it looks like ";
				}
				if ($days == 'tomorrow') $speech = "Tomorrow, you have ";
				if ($days == 'weekend') $speech = "This weekend, you have ";
				if (preg_match("/day/",$days)) $speech = "On ".$days." you have ";
				$names = [];
				foreach ($list as $upcoming) {
					array_push($names,$upcoming['title']);
				}
				$names = array_unique($names);
				write_log("Names: ".json_encode($names));
				if (count($names) >= 2) {
					foreach ($names as $name) {
						if ($i == count($names)) {
							$speech .= "and " . $name;
						} else {
							$speech .= $name . ', ';
						}
						$i++;
					}
				} else $speech .= $names[0];
				$tail = ".";
				if ($days == 'now') $tail = " is on the schedule.";
				if ((preg_match("/day/",$days)) || ($days == 'tomorrow') || ($days == 'weekend')) $tail= " on the schedule.";
				$speech .= $tail;
			} else {
				$speech = "Sorry, it doesn't look you have any scheduled recordings for that day.";
			}
			returnSpeech($speech,$contextName,false,false);
			$queryOut['speech'] = $speech;
			log_command(json_encode($queryOut));
			die();
		}

		// Start handling playback commands now"
		if (($action == 'play') || ($action == 'playfromlist')) {
            if (!($command)) {
			    write_log("This does not have a command.  Checking for a different identifier.");
				foreach($request["result"]['parameters'] as $param=>$value) {
					if ($param == 'type') {
						$mediaResult = fetchRandomNewMedia($value);
						$queryOut['parsedCommand'] = 'Play a random ' .$value;
					}
				}
			} else {
				if ($action == 'playfromlist') {
                    $cleanedRaw = cleanCommandString($rawspeech);
					$list = $GLOBALS['config']->get('user-_-'.$_SESSION['plexUserName'],'mlist',false);
					$list = base64_decode($list);
					write_log("Decode List: ".$list);
					if ($list) $_SESSION['mediaList'] = json_decode($list,true);
					write_log("So, we have a list to play from, neat.");
					foreach($_SESSION['mediaList'] as $mediaItem) {
						write_log("MediaItemJSON: ".json_encode($mediaItem));
						$title = cleanCommandString($mediaItem['title']);
						if ($year) $title .= " ".$mediaItem['year'];
						$weight = similarity($title,$cleanedRaw);
						$sameYear = (trim($command) === trim($mediaItem['year']));
						write_log("Weight of ".$title." versus ".$cleanedRaw." is ".$weight.".");
						if (($weight >=.8) || $sameYear) {
							$mediaResult = [$mediaItem];
							break;
						}
					}
					if (! $mediaResult) {
						if (preg_match('/none/',$cleanedRaw) || preg_match('/neither/',$cleanedRaw) || preg_match('/nevermind/',$cleanedRaw) || preg_match('/cancel/',$cleanedRaw)) {
							$speech = "Okay.";
						} else {
							$speech = "I'm sorry, but '".$rawspeech."' doesn't seem to match anything I just said.";
						}
						returnSpeech($speech,$contextName);
						die();
					}
				} else {
					$mediaResult = parsePlayCommand(strtolower($command),$year,$artist,$type);
				}
			}
			if (isset($mediaResult)) {
                write_log("Media result retrieved.");
				if (count($mediaResult)==1) {
				    write_log("Got media, sending play command.");
					$queryOut['mediaResult'] = $mediaResult[0];
					$searchType = $queryOut['mediaResult']['searchType'];
					$title = $queryOut['mediaResult']['title'];
					$year = $queryOut['mediaResult']['year'];
					$type = $queryOut['mediaResult']['type'];
					$tagline = $queryOut['mediaResult']['tagline'];
					$summary = $queryOut['mediaResult']['summary'] ?? false;
					$thumb = $queryOut['mediaResult']['art'];
					$queryOut['parsedCommand'] = 'Play the '.(($searchType == '') ? $type : $searchType). ' named '. $title.'.';
					unset($affirmatives);
					$affirmatives = array("Yes captain, ","Okay, ","Sure, ","No problem, ","Yes master, ","You got it, ","As you command, ","Allrighty then, ");
					$titlelower = strtolower($title);
					switch($titlelower) {
						case (strpos($titlelower, 'batman') !== false):
							$affirmative = "Holy pirated media!  ";
							break;
						case (strpos($titlelower, 'ghostbusters') !== false):
							$affirmative = "Who you gonna call?  ";
							break;
						case (strpos($titlelower, 'iron man') !== false):
							$affirmative = "Yes Mr. Stark, ";
							break;
						case (strpos($titlelower, 'avengers') !== false):
							$affirmative = "Family assemble! ";
							break;
						case (strpos($titlelower, 'frozen') !== false):
							$affirmative = "Let it go! ";
							break;
						case (strpos($titlelower, 'space odyssey') !== false):
							$affirmative = "I am afraid I can't do that Dave.  Okay, fine, ";
							break;
						case (strpos($titlelower, 'big hero') !== false):
							$affirmative = "Hello, I am Baymax, I am going to be ";
							break;
						case (strpos($titlelower, 'wall-e') !== false):
							$affirmative = "Thank you for shopping Buy and Large, and enjoy as we begin ";
							break;
						case (strpos($titlelower, 'evil dead') !== false):
							$affirmative = "Hail to the king, baby! "; //"playing Evil Dead 1/2/3/(2013)"
							break;
						case (strpos($titlelower, 'fifth element') !== false):
							$affirmative = "Leeloo Dallas Mul-ti-Pass! "; //"playing The Fifth Element"
							break;
						case (strpos($titlelower, 'game of thrones') !== false):
							$affirmative = "Brace yourself...";
							break;
						case (strpos($titlelower, 'they live') !== false):
							$affirmative = "I'm here to chew bubblegum and ";
							break;
						case (strpos($titlelower, 'heathers') !== false):
							$affirmative = "Well, charge me gently with a chainsaw.  ";
							break;
						case (strpos($titlelower, 'star wars') !== false):
							$affirmative = "These are not the droids you're looking for.  ";
							break;
						default:
							$affirmative = false;
							break;
					}
					// Put our easter egg affirmative in the array of other possible options, so it's only sometimes used.

					if ($affirmative) array_push($affirmatives,$affirmative);

					// Make sure we didn't just say whatever affirmative we decided on.
					do {
						$affirmative = $affirmatives[array_rand($affirmatives)];
					} while ($affirmative == $_SESSION['affirmative']);

					// Store the last affirmative.
					$_SESSION['affirmative'] = $affirmative;

					if ($type == 'episode') {
						$seriesTitle = $queryOut['mediaResult']['grandparentTitle'];
						$speech = $affirmative. "Playing the episode of ". $seriesTitle ." named ".$title.".";
						$title = $seriesTitle . ' - '.$title." (".$year.")";
					} else if (($type == 'track') || ($type == 'album')) {
					    write_log("Got a thing here: ".json_encode($queryOut['mediaResult']));
                        $artist = $queryOut['mediaResult']['grandparentTitle'];
					    $title = $artist . ' - '.$title;
					    $tagline = $queryOut['mediaResult']['parentTitle']." (".$year.")";
                        $speech = $affirmative. "Playing ".$title. " by ".$artist.".";
                    } else {
                        $title = $title." (".$year.")";
						$speech = $affirmative. "Playing ".$title.".";
					}
					if ($_SESSION['promptfortitle'] == true) {
						$contextName = 'promptfortitle';
						$_SESSION['promptfortitle'] = false;
					}
					write_log("Screen: ".$screen);
					write_log("MediaresultJSON: ".json_encode($queryOut['mediaResult']));
                    $card = [["title"=>$title,"subtitle"=>$tagline,'image'=>['url'=>$thumb]]];
                    if ($summary) $card[0]['formatted_text'] = $summary;
					returnSpeech($speech,$contextName,$card);
                    $playResult = playMedia($mediaResult[0]);
					$exact = $mediaResult[0]['@attributes']['exact'];
					$queryOut['speech'] = $speech;
                    $queryOut['card'] = $card;
					$queryOut['mediaStatus'] = "SUCCESS: ".($exact ? 'Exact' : 'Fuzzy' )." result found";
					$queryOut['playResult'] = $playResult;
					log_command(json_encode($queryOut));
					unset($_SESSION['deviceArray']);
					die();
				}

				if (count($mediaResult)>=2) {
					write_log("Got multiple results, prompting for moar info.");
					$speechString = "";
					$resultTitles = array();
					$count = 0;
					$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'mlist',base64_encode(json_encode($mediaResult)));
					saveConfig($GLOBALS['config']);
					write_log("MR: ".print_r($_SESSION['mediaList'],true));
					$cards = [];
					foreach($mediaResult as $Media) {
                        $title = $Media['title'];
                        $year = $Media['year'];
                        $tagline = $Media['tagline'] ?? $Media['summary'];
                        $thumb = $Media['art'];

                        $count++;
						write_log("Counting: ".$count. " and ". count($mediaResult));
						if ($count == count($mediaResult)) {
							$speechString .= " or ".$title." ".$year.".";
						} else {
							$speechString .= " ".$title." ".$year.",";
						}
						array_push($resultTitles,$title." ".$year);
                        $card = ["title"=>$title,"description"=>$tagline,'image'=>['url'=>$thumb],"key"=>"play ".$title];
						array_push($cards,$card);
					}
                    $queryOut['card'] = $cards;
					$speech = "I found several possible results for that, which one was it?  ". $speechString;
					$contextName = "promptfortitle";
					$_SESSION['promptfortitle'] = true;
					if (isset($_SESSION['mediaList'])) unset($_SESSION['mediaList']);
					//TODO - enable card when fixed
					returnSpeech($speech,$contextName,false,true);
					$queryOut['parsedCommand'] = 'Play a media item named '.$command.'. (Multiple results found)';
					$queryOut['mediaStatus'] = 'SUCCESS: Multiple Results Found, prompting user for more information';
					$queryOut['speech'] = $speech;
					$queryOut['playResult'] = "Not a media command.";
					log_command(json_encode($queryOut));
					unset($_SESSION['deviceArray']);
					die();
				}
				if (! count($mediaResult)) {
                    if ($command) {
                        if (isset($_SESSION['cleaned_search'])) {
                            $command = $_SESSION['cleaned_search'];
                            unset($_SESSION['cleaned_search']);
                        }
                        $speech = "I'm sorry, I was unable to find ".$command." in your library.  Would you like me to add it to your watch list?";
                        $contextName = 'yes';
                        $suggestions = ['Yes','No'];
                        returnSpeech($speech,$contextName,false,true,$suggestions);
                        $queryOut['parsedCommand'] = "Play a media item with the title of '".$command.".'";
                        $queryOut['mediaStatus'] = 'ERROR: No results found, prompting to download.';
                        $queryOut['speech'] = $speech;
                        log_command(json_encode($queryOut));
                        die();
                    }
                }
			}
		}


		if (($action == 'player') || ($action == 'server')) {
			$speechString = '';
			write_log("Got a request to change ".$action);
			unset($_SESSION['deviceArray']);
			$type = (($action == 'player') ? 'clients' : 'servers');
			$list = $_SESSION['list_plexdevices'] ?? scanDevices();
			$list = $list[$type];
            $speech = "There was an error retrieving the list of devices, please try again later.";
            $contextName = "yes";
            $waitForResponse = false;
			if (count($list) >=2) {
                $suggestions = [];
				$_SESSION['deviceArray'] = $list;
				$_SESSION['type'] = $action;
				$count = 0;
				foreach($list as $device) {
				    array_push($suggestions, $device['name']);
					$count++;
					if ($count == count($list)) {
						$speechString .= " or ".$device['name'].".";
					} else {
						$speechString .= " ".$device['name'].",";
					}
				}
				$speech = "Change ".$action.", sure.  What device would you like to use? ".$speechString;
				$contextName = "waitforplayer";
				$waitForResponse = true;
			}
			if (count($list) == 1) {
			    $suggestions = false;
				$speech = "I'd like to help you with that, but I only see one ".$action." that I can currently talk to.";
				$contextName = "waitforplayer";
				$waitForResponse = false;
			}
			returnSpeech($speech,$contextName,false,$waitForResponse,$suggestions);
			$queryOut['parsedCommand'] = 'Switch '.$action.'.';
			$queryOut['mediaStatus'] = 'Not a media command.';
			$queryOut['speech'] = $speech;
			log_command(json_encode($queryOut));
			die();

		}

		if ($action == 'fetchAPI') {
			$response = $request["result"]['parameters']["YesNo"];
			if ($response == 'yes') {

				$action = 'fetch';
			} else {
				$speech = "Okay, let me know if you change your mind.";
				returnSpeech($speech,$contextName);
				die();
			}
		}

		if (($action == 'fetch') && ($command)) {
			$queryOut['parsedCommand'] = 'Fetch the media named '.$command.'.';
			$result = parseFetchCommand($command,$type);
			$media = $result['mediaResult'];
			$stats = explode(":",$result['status']);
			write_log("MediaResult: ".json_encode($result));
			if ($stats[0] === 'SUCCESS') {
                $queryOut['mediaResult'] = $media;
                $resultTitle = $media['title'] ?? $media['@attributes']['title'];
                $resultYear = $media['year'];
                $resultImage = $media['art'];
                $resultSummary = $media['summary'];
                $resultSubtitle = $media['subtitle'];
                $resultData['image'] = $resultImage;
                write_log("Should have been successful.");
                if (preg_match("/Already/", $stats[1])) {
                    write_log("Success and in searcher.");
                    $speech = "It looks like " . $resultTitle . " is already set to download.";
                } else {
                    write_log("Success and not in searcher?");
                    $speech = "Okay, I've added " . $resultTitle . " (" . $resultYear . ") to the fetch list.";
                }
                $card = [["title" => $resultTitle . " (" . $resultYear . ")", "subtitle" => $resultSubtitle, "formatted_text" => $resultSummary, 'image' => ['url' => $resultImage]]];
                returnSpeech($speech, $contextName, $card);
                $queryOut['mediaStatus'] = $result['status'];
                $queryOut['card'] = $card;
                $queryOut['speech'] = $speech;
                log_command(json_encode($queryOut));
                unset($_SESSION['deviceArray']);
                die();
            } else {
				$speech = "Unfortunately, I was not able to find anything with that title to download.";
				returnSpeech($speech,$contextName);
				$queryOut['mediaStatus'] = $result['status'];
				$queryOut['speech'] = $speech;
				log_command(json_encode($queryOut));
				unset($_SESSION['deviceArray']);
				die();
			}
		}

		if (($action == 'control') || ($control != '')) {
			if ($action == '') $command = cleanCommandString($control);
            $speech = 'Sending a command to '.$command;
			if (preg_match("/volume/",$command)) {
				$int = strtolower($request["result"]["parameters"]["percentage"]);
				if ($int != '') {
					$command .= " " . $int;
					$speech = "Okay, setting the volume to ".$int;
				} else {
					if (preg_match("/up/",$rawspeech)) {
						write_log("UP, UP, UP");
						$command .= " UP";
						$speech = "Okay, I'll turn it up a little.";
					}
					if (preg_match("/down/",$rawspeech)) {
						write_log("DOWN, DOWN, DOWN");
						$command .= " DOWN";
						$speech = "Okay, I'll turn it down a little.";
					}
				}
			} else {
                write_log("Switching for command");
				switch ($command) {
					case "resume":
					case "play":
						$speech = 'Resuming playback.';
						break;
					case "stop":
						$speech = 'Plex should now be stopped.';
						break;
					case "pause":
						$speech = 'Plex should now be paused.';
						break;
					case "subtitleson":
						$speech = 'Subtitles have been enabled.';
						$queryOut['parsedCommand'] = "Enable Subtitles.";
						break;
					case "subtitlesoff":
						$speech = 'Subtitles have been disabled.';
						$queryOut['parsedCommand'] = "Disable Subtitles.";
						break;
					default:
						$speech = 'Sending a command to '.$command;
                        $queryOut['parsedCommand'] = $command;
				}
			}
			$queryOut['speech'] = $speech;
			returnSpeech($speech,$contextName);
			if ($command == 'jump to') {
				write_log("This is a jump command, raw speech was ".$rawspeech);
			}
			$result = parseControlCommand($command);
			$newCommand = json_decode($result,true);
			$newCommand = array_merge($newCommand,$queryOut);
			$newCommand['timestamp'] = timeStamp();
			$result = json_encode($newCommand);
			log_command($result);
			unset($_SESSION['deviceArray']);
			die();

		}

			// Say SOMETHING if we don't undersand the request.
		$unsureAtives = array("I'm afraid I don't understand what you mean by ".$rawspeech.".","Unfortunately, I couldn't figure out to do when you said '".$rawspeech."'.","Danger Will Robinson!  Command '".$rawspeech."' not understood!","I'm sorry, your request of '".$rawspeech."' does not compute.");
		$speech = $unsureAtives[array_rand($unsureAtives)];
		$contextName = 'playmedia';
		returnSpeech($speech,$contextName);
		$queryOut['parsedCommand'] = 'Command not recognized.';
		$queryOut['mediaStatus'] = 'ERROR: Command not recognized.';
		$queryOut['speech'] = $speech;
		log_command(json_encode($queryOut));
		unset($_SESSION['deviceArray']);
		die();


	}
	//
	// ############# Client/Server Functions ############
	//


	// The process of fetching and storing devices is too damned tedious.
	// This aims to address that.

	function scanDevices($force=false) {
	    $castDevices = $clients = $dvrs = $results = $servers = [];
	    $localContainer = false;
        $now = microtime(true);
        $rescanTime = $_SESSION['rescanTime'] ?? 8;
        $lastCheck = $_SESSION['last_fetch'] ?? ceil(round($now) / 60) - $rescanTime;
        $list = $_SESSION['list_plexdevices'];
        $diffSeconds = round($now - $lastCheck);
        $diffMinutes = ceil($diffSeconds / 60);

        // Set things up to be recached
        if (($diffMinutes >= $rescanTime) || $force || (! count($list['servers']))) {
           if ($force) write_log("Force-recaching devices.","INFO");
        	if ($diffMinutes >= $rescanTime) write_log("Recaching due to timer: ".$diffMinutes." versus ".$_SESSION['rescanTime'],"INFO");
        	if (! count($list['servers'])) write_log("Recaching due to missing servers");
        	$_SESSION['last_fetch'] = $now;

            if ($_SESSION['useCast']) {
				$castDevices = fetchCastDevices();
            }

            $url = 'https://plex.tv/api/resources?includeHttps=1&includeRelay=0&X-Plex-Token=' . $_SESSION['plexToken'];

            write_log('URL is: ' . protectURL($url));
            $container = simplexml_load_string(curlGet($url));

            if (isset($_SESSION['plexServerUri'])) {
		        write_log("Checking for local clients.");
		        $url = $_SESSION['plexServerUri'].'/clients?X-Plex-Token='.$_SESSION['plexServerToken'];
	            $localContainer = simplexml_load_string(curlGet($url)) ?? false;
            }
            // Split them up
            if ($container) {
                foreach ($container->children() as $deviceXML) {
                    $dev = json_decode(json_encode($deviceXML), true);
                    unset($device);
                    $device = (array)$dev['@attributes'];
                    $present = ($device['presence'] == 1);

                    if ($present) {
                        $device['public'] = $publicMatches = ($device['publicAddressMatches'] === "1");
                        $device['id'] = $device['clientIdentifier'];
                        $device['token'] = $device['accessToken'] ?? $_SESSION['plexToken'];
                        $connections = [];
                        write_log("Checking connections for " . $device['name']);
                        $protocol = ($device['httpsRequired'] === "1") ? 'https' : 'http';
                        foreach ($deviceXML->Connection as $connection) {
                            $con = json_decode(json_encode($connection), true);
                            if ((filter_var($con['@attributes']['address'], FILTER_VALIDATE_IP,
	                            FILTER_FLAG_NO_RES_RANGE)) || (preg_match("/plex.services/",$con['@attributes']['address']))) {
	                            if ((isset($list['servers'])) && ($device['product'] === 'Plex Media Server')) {
		                            foreach ($list['servers'] as $server) {
			                            if ($server['id'] === $device['id']) {
				                            $device['uri'] = $server['uri'];
			                            }
		                            }
	                            }
	                            if ((isset($list['clients'])) && ($device['product'] !== 'Plex Media Server')) {
		                            foreach ($list['clients'] as $client) {
			                            if ($client['id'] === $device['id']) {
				                            $device['uri'] = $client['uri'];
			                            }
		                            }
	                            }
	                            if (!isset($device['uri'])) {
		                            if ($device['product'] === 'Plex Media Server') {
			                            if ($con['@attributes']['local'] == $device['publicAddressMatches']) {
				                            $fallback = $protocol . "://" . $con['@attributes']['address'] . ":" . $con['@attributes']['port'];
				                            $url = $fallback . "?X-Plex-Token=" . $device['token'];
				                            if ((check_url($con['@attributes']['uri'] . "?X-Plex-Token=" . $device['token']))) $device['uri'] = $con['@attributes']['uri'];
			                            }
		                            }
		                            if (($device['product'] !== 'Plex Media Server') && ($con['@attributes']['local'] == 1)) {
			                            if (check_url($con['@attributes']['uri'] . '/resources?X-Plex-Token=' . $device['token'])) $device['uri'] = $con['@attributes']['uri'];
		                            }
	                            }
	                            if ($con['@attributes']['local'] == 0) {
		                            $device['publicUri'] = $protocol . "://" . $con['@attributes']['address'] . ":" . $con['@attributes']['port'];
	                            }
	                            array_push($connections, (array)$con['@attributes']);
                            } else write_log("IP is loopback, filtering: ".$con['@attributes']['address'],"INFO");
                        }
                        $device['Connection'] = $connections;

                        if ((! isset($device['uri'])) && ($device['product'] === 'Plex Media Server')) {
                            write_log("Trying fallback URL for ".$device['name'].": " . protectURL($url));
                            if (check_url($url)) $device['uri'] = $fallback ?? false;
                        }
                        if (isset($device['accessToken'])) unset($device['accessToken']);
                        if (isset($device['clientIdentifier'])) unset($device['clientIdentifier']);
                        if (isset($device['uri'])) {
	                        if ($device['product'] === 'Plex Media Server') {
		                        $i=2;
		                        foreach($servers as $check) {
			                        $dname = preg_replace("/[^a-zA-Z]/", "", $device['name']);
			                        $cname = preg_replace("/[^a-zA-Z]/", "", $check['name']);
			                        write_log("Checking ".$check['name']. " versus ".$device['name']);
			                        if ($dname == $cname) {
				                        $device['name'] .= " ($i)";
				                        $i++;
			                        }
		                        }
		                        array_push($servers, $device);
	                        } else {
	                        	write_log("This should be a client?  ".json_encode($device));
		                        $i=2;
		                        foreach($clients as $check) {
			                        $dname = preg_replace("/[^a-zA-Z]/", "", $device['name']);
			                        $cname = preg_replace("/[^a-zA-Z]/", "", $check['name']);
			                        write_log("Checking ".$check['name']. " versus ".$device['name']);
			                        if ($dname == $cname) {
				                        $device['name'] .= " ($i)";
				                        $i++;
			                        }
		                        }
		                        array_push($clients, $device);
	                        }
                        }
                    }
                }
            }

	        if ($localContainer) {
            	foreach ($localContainer->children() as $deviceXML) {
			        $dev = json_decode(json_encode($deviceXML), true);
			        unset($device);
			        $device = (array)$dev['@attributes'];
			        write_log("Here's a local device: " . json_encode($device));
			        $item = ['name'=>$device['name'],
				        'id'=>$device['machineIdentifier'],
				        'uri'=>'http://'.$device['address'].':'.$device['port'],
				        'product'=>$device['product']];
			        foreach ($clients as $client) {
			        	if ($client['id'] == $item['id']) $item = false;
			        }
			        if (is_array($item)) array_push($clients,$item);
		        }
	        }

            if (count($servers)) {
                foreach ($servers as $server) {
                    if (($server['owned']) && ($server['platform'] !== 'Cloud')) {
                        $url = $server['uri'] . '/tv.plex.providers.epg.onconnect:4?X-Plex-Token=' . $server['token'];
                        $epg = curlGet($url);
                        if ($epg) {
                            if (preg_match('/mediaTagPrefix/', $epg)) {
                                array_push($dvrs, $server);
                            }
                        }
                    }
                }
            }

			if ($castDevices) {
				write_log("Found cast devices here...");
				foreach($castDevices as $device) {
					$i=2;
					foreach($clients as $check) {
						$dname = preg_replace("/[^a-zA-Z]/", "", $device['name']);
						$cname = preg_replace("/[^a-zA-Z]/", "", $check['name']);
						write_log("Checking ".$check['name']. " versus ".$device['name']);
						if ($dname == $cname) {
							$device['name'] .= " ($i)";
							$i++;
						}
					}
					write_log("Cast device: ".json_encode($device));
					array_push($clients,$device);
				}
			}
            $results['servers'] = $servers;
            $results['clients'] = $clients;
            $results['dvrs'] = $dvrs;
            $_SESSION['list_plexdevices'] = $results;
            $string = base64_encode(json_encode($results));
            $GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'dlist',$string);
            saveConfig($GLOBALS['config']);
            foreach($results as $section=>$devices) {
	            write_log("Results::".ucfirst($section) . ": " . json_encode($devices));
            }

        } else $results = $list;



        return $results;
    }

    // Call this after changing the target server so that we have the section UUID's stored
    function fetchSections() {
	    $sections = [];
        $url = $_SESSION['plexServerUri'].'/library/sections?X-Plex-Token='.$_SESSION['plexServerToken'];
        $results = curlGet($url);
        if ($results) {
            $container = new SimpleXMLElement($results);
            if ($container) {
                foreach($container->children() as $section) {
                    array_push($sections,["id"=>(string)$section['key'],"uuid"=>(string)$section['uuid'],"type"=>(string)$section['type']]);
                }
            }else {
	            write_log("Error retrieving section data!","ERROR");
        }
        }
        if (count($sections)) $_SESSION['sections'] = $sections;
        return $sections;
    }

	/// What used to be a big ugly THING is now just a wrapper and parser of the result of scanDevices
	function fetchClientList($devices) {
		write_log("Function Fired.");
		$options = "";
		if (isset($devices['clients'])) {
			if (!(isset($_GET['pollPlayer']))) write_log("Client list retrieved.");
			foreach($devices['clients'] as $key => $client) {
                $selected = (trim($client['id']) == trim($_SESSION['plexClientId']));
                if ($selected) write_log("This device is selected: ".$client['name']);
				$id = $client['id'];
				$name = $client['name'];
				$uri = $client['uri'];
				$product = $client['product'];
				$displayName = $name;
				$options.='<a class="dropdown-item client-item'.(($selected) ? ' dd-selected':'').'" href="#" product="'.$product.'" value="'.$id.'" name="'.$name.'" uri="'.$uri.'">'.ucwords($displayName).'</a>';
			}
			$options.='<a class="dropdown-item client-item" value="rescan"><b>rescan devices</b></a>';
		}
		return $options;
	}


	// Fetch a list of servers for playback
	function fetchServerList($devices) {
		write_log("Function Fired.");
		$current = $GLOBALS['config']->get('user-_-' . $_SESSION['plexUserName'], 'plexServerId', false);
		$options = "";
		if (isset($devices['servers'])) {
			foreach($devices['servers'] as $key => $client) {
                $selected = ($current ? (trim($client['id']) == trim($current)) : $key===0);
				$id = $client['id'];
				$name = $client['name'];
				$uri = $client['uri'];
				$token = $client['token'];
				$product = $client['product'];
				$publicAddress = $client['publicUri'] ?? "";
                $options .= '<option type="plexServerId" publicUri="'. $publicAddress .'" product="'.$product.'" value="'
	                .$id
	                .'" uri="'.$uri.'" name="'.$name.'" '.' token="'.$token.'" '.($selected ? ' selected':'').'>'.ucwords($name).'</option>';
			}
		}
		return $options;
	}

	function fetchDVRList($devices) {
        write_log("Function fired.");
		$current = $GLOBALS['config']->get('user-_-' . $_SESSION['plexUserName'], 'plexDvrId', false);
		$options = "";
		if (isset($devices['dvrs'])) {
			$options = "";
			foreach($devices['dvrs'] as $key => $client) {
                $selected = ($current ? (trim($client['id']) == trim($current)) : $key===0);
				$id = $client['id'];
				$name = $client['name'];
				$uri = $client['uri'];
				$token = $client['token'];
				$product = $client['product'];
                $publicAddress = (isset($client['publicUri']) ? " publicAddress='".$client['publicUri']."'" : "");
				$options .= '<option type="plexDvr" '. $publicAddress .' product="'.$product.'" value="'.$id.'" uri="'.$uri.'" name="'.$name.'" token="'.$token.'" '.($selected ? ' selected':'').'>'.ucwords($name).'</option>';
			}
		}
		return $options;
	}


	// Fetch a transient token from our server, might be key to proxy/offsite playback
	function fetchTransientToken() {
		write_log("Function Fired.");
		$url = $_SESSION['plexServerUri'].
		'/security/token?type=delegation&scope=all'.
		$_SESSION['plexHeader'];
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			$ttoken = (string)$container['token'];
			if ($ttoken) {
				$_SESSION['transientToken'] = $ttoken;
				write_log("Transient token is valid: ".substr($ttoken,0,5));
				return $ttoken;
			}
		}
		return false;
	}


	//
	// ############# Media Find Functions ############
	//



	// Once we have parsed the play string and stripped out what we think are key terms,
	//send it over here to figure out if we have media that matches the user's query.
	function fetchInfo($matrix) {
		write_log("Function Fired.");
		$episode = $epNum = $num = $preFilter = $season = $selector = $type = $winner = $year = false;
		$title = $matrix['target'];
		unset($matrix['target']);
		$offset = 'foo';
		$nums = $matrix['num'];
		$media = $matrix['media'];
		$artist = $matrix['artist'] ?? false;
        $type = $matrix['type'] ?? false;

		foreach($matrix as $key=>$mod) {
			write_log("Mod string: " .$key.": " .implode(", ",$mod));
			if ($key=='media') {
				foreach($mod as $flag) {
					if (($flag=='movie') || ($flag=='show')) {
						write_log("Media modifier is ".$flag);
						$type = $flag;
					}
					if(($key = array_search($type, $media)) !== false) {
						unset($media[$key]);
					}
				}
			}
			if ($key=='filter') {
				foreach($mod as $flag) {
					if (($flag=='movie') || ($flag=='show')) {
						write_log("Media modifier is ".$flag);
						$type = $flag;
					}
					if(($key = array_search($type, $media)) !== false) {
						unset($media[$key]);
					}
				}
			}
			if ($key=='preFilter') {
				write_log("We have a preFilter: ".$mod);
				$preFilter = $mod;
			}
		}
		$searchType = $type;
		$matchup = array();
		write_log("Mod counts are ".count($media). " and ".count($nums));
		if ((count($media) == count($nums)) && (count($media))) {
			write_log("Merging arrays.");
			$matchup = array_combine($media, $nums);
		} else {
			write_log("Number doesn't appear to be related to a context, re-appending.");
			if ($preFilter) $title = $preFilter;
		}

		if (count($matchup)) {
			foreach($matchup as $key=>$mod) {
				write_log("Mod string: " . $key . ": " . $mod);
				if (($key == 'offset') || ($key == 'hh') || ($key == 'mm') || ($key == 'ss')) {
					$offset = 0;
				}
			}
			foreach($matchup as $key=>$mod) {
				switch ($key) {
					case 'hh':
						$offset += $mod*60*60*1000;
						break;
					case 'mm':
						$offset += $mod*60*1000;
						break;
					case 'ss':
						$offset += $mod*1000;
						break;
					case 'offset':
						$offset = $mod;
						break;
					case 'season':
						$type = 'show';
						$season = $mod;
						break;
					case 'movie':
						$type = 'movie';
						break;
					case 'episode':
						$type = 'show';
						$episode = $mod;
						break;
					case 'year':
						$year = $mod;
						break;
				}
			}
		}

		if ($offset !== 'foo') write_log("Offset has been set to ".$offset);

		checkString: {
		$winner = false;
			$results = fetchHubResults(strtolower($title),$type,$artist);
			if ($results) {
				if ((count($results)>=2) && (count($matchup))) {
					write_log("Multiple results found, let's see if there are any mods.");
					foreach($results as $result) {
						if ($year == $result['year']) {
							write_log("Hey, this looks like it matches." . $result['year']);
							$result['searchType'] = $searchType;
							$results = array($result);
							break;
						}
					}
				}

				// If we have just one result, check to see if it's a show.
				if (count($results)==1) {
					$winner = $results[0];
					if ($winner['type']=='show') {
						$showResult = $winner;
						$winner = false;
						write_log("This is a show, checking for modifiers.");
						$key = $showResult['key'];
						if (($season) || (($episode) && ($episode >= 1))) {
						    write_log("Doing some season stuff.");
							if (($season) && ($episode)) {
								$selector = 'season';
								$num = $season;
								$epNum = $episode;
							}
							if (($season) && (! $episode)) {
								$selector = 'season';
								$num = $season;
								$epNum = false;
							}
							if ((! $season) && ($episode)) {
								$selector = 'episode';
								$num = $episode;
								$epNum = false;
							}
							write_log("Mods Found, fetching a numbered TV Item.");
							if ($num && $selector) $winner = fetchNumberedTVItem($key,$num,$selector,$epNum);
						}
						if ($episode == -2) {
							write_log("Mods Found, fetching random episode.");
							$winner = fetchRandomEpisode($key);
						}
						if ($episode == -1) {
							write_log("Mods Found, fetching latest/newest episode.");
							$winner = fetchLatestEpisode($key);
						}
						if (! $winner) {
							write_log("No Mods Found, returning first on Deck Item.");
							$onDeck = $showResult->OnDeck->Video;
							if ($onDeck) {
								$winner = $onDeck;
							} else {
								write_log("Show has no on deck items, fetching first episode.");
								$winner = fetchFirstUnwatchedEpisode($key);
							}
							write_log("Winning JSON: ".json_encode($onDeck));
						}
					}
				}
			}
		}
		if ($winner) {
			write_log("We have a winner.  Title is ".$winner['title']);
			// -1 is our magic key to tell it to just use whatever is there

			$winner['art'] = transcodeImage($winner['art']);
			$winner['thumb'] = transcodeImage($winner['thumb']);
			if (($offset !== 'foo') && ($offset != -1)) {
                write_log("Appending offset for ".$winner['title']. ' to '.$winner['viewOffset']);
                $winner['viewOffset']=$offset;
            }
			$final = array($winner);
			return $final;
		} else {
		    $resultsOut = [];
		    foreach($results as $result) {
		        $result['art'] = transcodeImage($result['art']);
                $result['thumb'] = transcodeImage($result['thumb']);
                array_push($resultsOut,$result);
            }
			return $resultsOut;
		}
	}


	// This is our one-shot search mechanism
	// It queries the /hubs endpoint, scrapes together a bunch of results, and then decides
	// how relevant those results are and returns them to our talk-bot
	function fetchHubResults($title,$type=false,$artist=false) {
		write_log("Function Fired.");
		write_log("Type is ".$type);
		$title = cleanCommandString($title);
		$searchType = '';
		$url = $_SESSION['plexServerUri'].'/hubs/search?query='.urlencode($title).'&limit=30&X-Plex-Token='.$_SESSION['plexServerToken'];
		$searchResult['url'] = $url;
		$cast = $genre = $music = $queueID = false;
		write_log('URL is : '.protectURL($url));
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			$exactResults = array();
			$fuzzyResults = array();
			$castResults = array();
			$nameLocation = 'title';
            if (isset($container->Hub)) {
                foreach($container->Hub as $Hub) {
                    if ($Hub['size'] != "0") {
                        if (($Hub['type'] == 'show') || ($Hub['type'] == 'movie') || ($Hub['type'] == 'episode')|| ($Hub['type'] == 'artist')|| ($Hub['type'] == 'album')|| ($Hub['type'] == 'track')) {
                            $nameLocation = 'title';
                        }

                        if (($Hub['type'] == 'actor') || ($Hub['type'] == 'director')) $nameLocation = 'tag';

                        foreach($Hub->children() as $Element) {
                            $skip = false;
                            $titleOut = cleanCommandString((string)$Element[$nameLocation]);

                            if ($titleOut == $title) {
                                write_log("Title matches exactly: ".$title);
                                if (($Hub['type'] == 'actor') || ($Hub['type'] == 'director')) {
                                    $searchType = 'by cast';
                                    $cast = true;
                                }

                                if ($Hub['type'] == 'genre') {
                                    $genre = true;
                                    $searchType = 'by genre';
                                    unset($exactResult);
                                    foreach($Hub->children() as $dir) {
                                        $result = fetchRandomMediaByKey($dir['key']);
                                        array_push($exactResults,$result);
                                    }
                                }

                                if (($Hub['type'] == 'show') || ($Hub['type'] == 'movie') || ($Hub['type'] == 'episode')) {
                                    if ($type) {
                                        if ($Hub['type'] == $type) {
                                            array_push($exactResults,$Element);
                                        }
                                    } else {
                                        array_push($exactResults,$Element);
                                    }
                                }

                                if (($Hub['type'] == 'artist') || ($Hub['type'] == 'album') || ($Hub['type'] == 'track')) {
                                    if ($artist) {
                                        $foundArtist = (($Hub['type'] == 'track') ? $Element['grandparentTitle'] : $Element['parentTitle']);
                                        write_log("Artist: ".$foundArtist." Hub JSON: ".json_encode($Element));
                                        $foundArtist = cleanCommandString($foundArtist);
                                        write_log("Hub artist should be ".$foundArtist);
                                        if (cleanCommandString($artist) != $foundArtist) {
                                            write_log("The ".$Hub['type'].' matches, but the artist does not, skipping result.',"INFO");
                                            $skip = true;
                                        }
                                    }

                                    if ($type) {
                                        if ($Hub['type'] != $type) $skip = true;
                                    }

                                    if (! $skip) {
	                                    array_push($exactResults,$Element);
                                    }
                                }

                            } else {
                                if ($type) {
                                    if ($Hub['type'] != $type) $skip = true;
                                    write_log("Type specified but not matched, skipping.");
                                }
                                if ($artist) {
                                    $foundArtist = (($Hub['type'] == 'track') ? $Element['grandparentTitle'] : $Element['parentTitle']);
                                    write_log("Artist: ".$foundArtist." Hub JSON: ".json_encode($Element));
                                    $foundArtist = cleanCommandString($foundArtist);
                                    write_log("Hub artist should be ".$foundArtist);
                                    if (cleanCommandString($artist) != $foundArtist) {
                                        write_log("The ".$Hub['type'].' matches, but the artist does not, skipping result.',"INFO");
                                        $skip = true;
                                    }
                                }
                                if ($cast) {
                                	if ($type) {
                                		if ($Hub['type'] == $type) array_push($castResults,$Element);
	                                } else array_push($castResults,$Element);
                                }

                                if (! $skip) {
                                    $weight = similarity($title, $titleOut);
                                    write_log("Weight of " . $title . " vs " . $titleOut . " is " . $weight);
                                    if ($weight >= .36) {
                                        write_log("Heavy enough, pushing.");
                                        array_push($fuzzyResults, $Element);
                                    }
                                }
                            }
                        }
                    }
                }
            }


			if ((count($exactResults)) && (!($cast)) && (!($genre))) {
				write_log("Exact results found.");
				$exact = true;
				$finalResults = $exactResults;
			} else {
				write_log("Fuzzy results found.");
				$exact = false;
				$finalResults = array_unique($fuzzyResults);
			}

			if ($genre) {
				write_log("Detected override for ".($cast ? 'cast' : 'genre').".");
				$size = count($exactResults)-1;
				$random = rand(0,$size);
				$winner = array($exactResults[$random]);
				write_log("Result from ".($cast ? 'cast' : 'genre'). " search is ".print_r($winner,true));
				unset($finalResults);
				$finalResults=$winner;
			}

			if ($cast) {
				write_log("Detected override for a search by castmember.","INFO");
				$size = count($castResults)-1;
				$random = rand(0,$size);
				$winner = array($castResults[$random]);
				write_log("Result from cast search is ".json_encode($winner));
				unset($finalResults);
				$finalResults=$winner;
			}

			write_log("We have ".count($finalResults)." results.");
			// Need to check the type of each result object, make sure that we return a media result for each type
			$Returns = array();
			foreach($finalResults as $Result) {
			    $item = json_decode(json_encode($Result),true)['@attributes'];
				$thumb = $item['thumb'];
                $art = $item['art'];
                write_log("Item JSON: ".json_encode($item));
                if (!isset($item['summary'])) {
                    $extra = fetchMediaExtra($item['ratingKey']);
                    write_log("EXTRASUMMARY: ".json_encode($extra));
                    if ($extra) $item['summary'] = $extra['summary'];
                }
                $item['art'] = $art;
                $item['thumb'] = $thumb;
				$item['exact'] = $exact;
				$item['searchType'] = $searchType;
				write_log("ITEM: ".json_encode($item));
				array_push($Returns,$item);
			}
			write_log("Finals: ".json_encode($Returns));
			return $Returns;

		}
		return false;
	}

	function fetchHubList($section,$type=null) {
		$url = false;
		$baseUrl = $_SESSION['plexServerUri'];
		if ($section == 'recent') {
			write_log("Looking for recents");
			if ($type == 'show') {
				$url = $baseUrl . '/hubs/home/recentlyAdded?type=2';
			}
			if ($type == 'movie') {
				$url = $baseUrl . '/hubs/home/recentlyAdded?type=1';
			}

		}
		if ($section == 'ondeck') {
			write_log("Fetching on-deck list");
			$url = $baseUrl . '/hubs/home/onDeck?X-Plex-Container-Start=0';
		}
		if ($url) {
			$url = $url."&X-Plex-Token=".$_SESSION['plexServerToken']."&X-Plex-Container-Start=0&X-Plex-Container-Size=".$_SESSION['returnItems'] ?? 6;
			write_log("URL is ".$url);
			$result = curlGet($url);
			write_log("Result: ".$result);
			if ($result) {
                $container = new SimpleXMLElement($result);
			   	if ($container) {
                    $results = array();
                    if (isset($container->Video)) {
                        foreach ($container->Video as $video) {
                            $item = json_decode(json_encode($video),true)['@attributes'];
                            array_push($results, $item);
                        }
                    }
                    write_log("We got a container and stuff: " . json_encode($results));
                    if (!(empty($results))) return json_encode($results);
                }
            }
		}
		return false;
	}

	function fetchMediaExtra($ratingKey,$returnAll=false) {
        $extraURL = $_SESSION['plexServerUri'] . "/library/metadata/" . $ratingKey . "?X-Plex-Token=" . $_SESSION['plexServerToken'];
        write_log("Extras URL is " . $extraURL);
        $extra = curlGet($extraURL);
        if ($extra) {
            $extra = new SimpleXMLElement($extra);
            write_log("Media Extra: " . json_encode($extra));
            $extras =json_decode(json_encode($extra),true);
            if ($returnAll) return $extras;
            $extra = $extras['Video']['@attributes'] ?? $extras['Directory']['@attributes'];
	        write_log("Media Extra: " . json_encode($extra));
	        return $extra;
        }
        return false;
    }

	// Build a list of genres available to our user
	// Need to determine if this list is static, or changes depending on the collection
	// If static, MAKE IT A STATIC LIST AND SAVE THE CALLS
	function fetchAvailableGenres() {
		write_log("Function Fired.");
		$sectionsUrl = $_SESSION['plexServerUri'].'/library/sections?X-Plex-Token='.$_SESSION['plexServerToken'];
		write_log($sectionsUrl);
		$genres = array();
		$result = curlGet($sectionsUrl);
		if ($result) {
			$container = new SimpleXMLElement($result);
			foreach($container->children() as $section) {
				$url = $_SESSION['plexServerUri'].'/library/sections/'.$section->Location['id'].'/genre'.'?X-Plex-Token=' . $_SESSION['plexServerToken'];
				write_log("GenreSection url: ".$url);
				$result = curlGet($url);
				if ($result) {
					$container = new SimpleXMLElement($result);
                    if (isset($container->Directory)) {
                        foreach($container->Directory as $genre) {
                            $genres[strtolower($genre['fastKey'])] = $genre['title'];
                        }
                    }
				}
			}
			if (count($genres)) {
				return $genres;
			}
		}
		return false;
	}


	// We should pass something here that will be a directory of shows or movies
	function fetchRandomMediaByKey($key) {
	    $winner = false;
		write_log("Function Fired.");
		$url = $_SESSION['plexServerUri'].$key.'&limit=30&X-Plex-Token=' . $_SESSION['plexServerToken'];
		write_log('URL is : '.protectURL($url));
		$result = curlGet($url);
		if ($result) {
			$matches = array();
			$container = new SimpleXMLElement($result);
			foreach ($container->children() as $video) {
				array_push($matches,$video);
			}
			$size = sizeof($matches);
			write_log("Resulting array size is ".$size);
			if ($size > 0) {
				$winner = rand(0,$size);
				$winner = $matches[$winner];
				write_log("We got a winner!  Out of ".$size ."  choices, we found  ". $winner['title'] . " and key is " . $winner['key'] . $size);
				if ($winner['type'] == 'show') {
					$winner = fetchFirstUnwatchedEpisode($winner['key']);
				}
			}
		}
        if ($winner) {
            $item = json_decode(json_encode($winner),true)['@attributes'];
            $item['thumb'] = $_SESSION['plexServerPublicUri'].$winner['thumb']."?X-Plex-Token=".$_SESSION['plexServerToken'];
            $item['art'] = $_SESSION['plexServerPublicUri'].$winner['art']."?X-Plex-Token=".$_SESSION['plexServerToken'];
        }
		return $winner;
	}


	function fetchRandomNewMedia($type) {
		write_log("Function Fired.");
		$winner = false;
		$url = $_SESSION['plexServerUri'].'/library/recentlyAdded'.'?X-Plex-Token=' . $_SESSION['plexServerToken'];
		write_log("FetchRandomNew url for ".$type." is ".$url);
		$result = curlGet($url);
		if ($result) {
			$matches = array();
			$container = new SimpleXMLElement($result);
			foreach ($container->children() as $video) {
				if ($video['type'] == $type) {
					array_push($matches,$video);
				}
				if (($video['type'] == 'season') && ($type == 'show')) {
					array_push($matches,$video);
				}
			}
			write_log("I Got me sum matches!!: ".json_encode($matches));
			$size = sizeof($matches);
				if ($size > 0) {
					$winner = rand(0,$size-1);
					$winner = $matches[$winner];
					write_log("We got a winner!  Out of ".$size ."  choices, we found  ". ($type=='movie' ? $winner['title']:$winner['parentTitle']) . " and key is " . $winner['key'] . $size);
					if ($winner['type'] == 'season') {
						$result = fetchFirstUnwatchedEpisode($winner['parentKey'].'/children');
						write_log("I am going to play an episode named ".$result['title']);
						$winner = $result;
					}
				} else {
					write_log("Can't seem to find any random " . $type);
				}
		}
		if ($winner) {
            $item = json_decode(json_encode($winner),true)['@attributes'];
            $item['thumb'] = $_SESSION['plexServerPublicUri'].$winner['thumb']."?X-Plex-Token=".$_SESSION['plexServerToken'];
            $item['art'] = $_SESSION['plexServerPublicUri'].$winner['art']."?X-Plex-Token=".$_SESSION['plexServerToken'];
            $winner = [$item];
        }
		return $winner;

	}

	// Music function(s):
    function fetchTracks($ratingKey) {
	    $playlist = $queue = false;
	    $url = $_SESSION['plexServerUri'].'/library/metadata/'.$ratingKey.'/allLeaves?X-Plex-Token='.$_SESSION['plexServerToken'];
	    $result = curlGet($url);
        $data = [];
	    if ($result) {
	        $container = new SimpleXMLElement($result);
	        foreach($container->children() as $track) {
                $trackJSON = json_decode(json_encode($track),true);
	            if (isset($track['ratingCount'])) {
	                write_log("The track ".$track['title']." has a rating: ".$track['ratingCount']);
	                if ($track['ratingCount'] >= 1700000) array_push($data,$trackJSON['@attributes']);
                }
            }
        }
		
		usort($data, "cmp");
        write_log("Final array(".count($data)."): ".json_encode($data));
		foreach($data as $track) {
		    if (! $queue) {
		        $queue = queueMedia($track,true);
            } else {
		        queueMedia($track,true,$queue);
            }
		}
		write_log("Queue ID is ".$queue);
        return $playlist;
    }

	
	// Compare the ratings of songs and make an ordered list
	function cmp($a, $b) {
		if($b['ratingCount']==$a['ratingCount']) return 0;
		return $b['ratingCount'] > $a['ratingCount']?1:-1;
        
	}
	// TV Functions


	function fetchFirstUnwatchedEpisode($key) {
		write_log("Function Fired.");
		$mediaDir = preg_replace('/children$/', 'allLeaves', $key);
		$url = $_SESSION['plexServerUri'].$mediaDir. '?X-Plex-Token=' . $_SESSION['plexServerToken'];
		write_log("URL is ".protectURL(($url)));
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			foreach ($container->children() as $video)
			{
				if ($video['viewCount']== 0) {
					$video['art']=$container['art'];
					return $video;
				}
			}
			// If no unwatched episodes, return the first episode
            if (!empty($container->Video)) {
                return $container->Video[0];
            }
		}
		return false;
	}


	// We assume that people want to watch the latest unwatched episode of a show
	// If there are no unwatched, we'll play the newest one
	function fetchLatestEpisode($key) {
		write_log("Function Fired.");
		$last = false;
		$mediaDir = preg_replace('/children$/', 'allLeaves', $key);
		$url = $_SESSION['plexServerUri'].$mediaDir.'?X-Plex-Token=' . $_SESSION['plexServerToken'];
		write_log('URL is: '.protectURL($url));
		$result = curlGet($url);
		write_log("fetchlatest: Result string is ".$result);
		if ($result) {
			$container = new SimpleXMLElement($result);
            if (isset($container->Video)) {
                foreach($container->Video as $episode) {
                    $last = $episode;
                }
            }
		}
        return $last;
	}


	function fetchRandomEpisode($showKey) {
		write_log("Function Fired.");
		$results = false;
		$mediaDir = preg_replace('/children/', 'allLeaves', $showKey);
		$url = $_SESSION['plexServerUri'].$mediaDir.'?X-Plex-Token=' . $_SESSION['plexServerToken'];
		write_log('URL is: '.protectURL($url));
		$result = curlGet($url);
		if ($result) {
            $container = new SimpleXMLElement($result);
            $contArray = json_decode(json_encode($container), true);
            $parentArt = (string)$contArray['@attributes']['art'];

            if (isset($container->Video)) {
                $size = sizeof($container->Video);
                $winner = rand(0, $size);
                $result = $container->Video[$winner];
                $result = json_decode(json_encode($result), true);
                $result['@attributes']['art'] = $parentArt;
                $results = $result['@attributes'];
                $results['@attributes'] = $result['@attributes'];
            }
        }
        return $results;
	}


	function fetchNumberedTVItem($seriesKey, $num, $selector, $epNum=null) {
		write_log("Function Fired.");
		$match = false;
		write_log("Searching for ".$selector." number ". $num . ($epNum != null ? ' and episode number ' . $epNum : ''));
		$mediaDir = preg_replace('/children$/', 'allLeaves', $seriesKey);
		$url = $_SESSION['plexServerUri'].$mediaDir. '?X-Plex-Token=' . $_SESSION['plexServerToken'];
		write_log('URL is: '.protectURL($url));
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			write_log("Container: ".json_encode($container));
			// If we're specifying a season, get all those episodes who's ParentIndex matches the season number specified
			if ($selector == "season") {
				foreach($container as $ep) {
				    $episode = json_decode(json_encode($ep),true)['@attributes'];
				    write_log("Episode: ".json_encode($episode));
					if ($epNum) {
						if (($episode['parentIndex'] == $num) && ($episode['index'] == $epNum)) {
						    $match = $episode;
							$match['art']=$container['art'];
							break;
						}
					} else {
						if ($episode['parentIndex'] == $num) {
							write_log("Searching for a Season");
							$match['index'] = $episode['parentIndex'];
							$match['thumb']=$episode['parentThumb'];
							$match['art']=$container['art'];
							break;
						}
					}
				}
			} else {
			    if (isset($container[intval($num)-1])) {
                    $match = $container[intval($num) - 1];
                    $match['art'] = $container['art'];
                }
			}
		}
		write_log("Matching episode: ".json_encode($match));
		return $match;
	}


	// Movie Functions
	function fetchRandomMovieByYear($year) {
		write_log("Function Fired.");
		write_log("Someone wants a movie from the year " . $year);
		return $year;
	}


	function fetchRandomMediaByGenre($fastKey,$type=false) {
		write_log("Function Fired.");
		$serverToken = $_SESSION['plexServerToken'];
		$sectionsUrl = $_SESSION['plexServerUri'].$fastKey.'&X-Plex-Token='.$serverToken;
		write_log("Url is ". protectURL($sectionsUrl) . " type search is ".$type);
		$sectionsResult = curlGet($sectionsUrl);
		if ($sectionsResult) {
			$container = new SimpleXMLElement($sectionsResult);
			$winners = array();
			foreach ($container->children() as $directory) {
				if (($directory['type']=='movie') && ($type != 'show')) {
					write_log("fetchRandomMediaByGenre: Pushing  ". $directory['title']);
					array_push($winners,$directory);
				}
				if (($directory['type']=='show') && ($type != 'movie')) {
					$media = fetchLatestEpisode($directory['title']);
					write_log("Pushing  ". $directory['title']);
					if ($media) array_push($winners,$media);
				}
			}
			$size = sizeof($winners);
			if ($size > 0) {
				$winner = rand(0,$size);
				$winner = $winners[$winner];
				write_log("WE GOT A WINNER!! ". $winner['title']);
				return $winner;
			}
		}
		return false;
	}


	function fetchRandomMediaByCast($actor,$type='movie') {
		write_log("Function Fired.");
		$result = $section = false;
		$serverToken = $_SESSION['plexServerToken'];
		$sectionsUrl = $_SESSION['plexServerUri'].'/library/sections?X-Plex-Token='.$serverToken;
		$sectionsResult = curlGet($sectionsUrl);
		$actorKey = false;
		if ($sectionsResult) {
			$container = new SimpleXMLElement($sectionsResult);
			foreach ($container->children() as $directory) {
				write_log("Directory type ". $directory['type']);
				if ($directory['type']==$type) {
					$section = $directory->Location['id'];
				}
			}
		} else {
			write_log("Unable to list sections");
			return false;
		}
		if ($section) {
            $url = $_SESSION['plexServerUri'] . '/library/sections/' . $section . '/actor' . '?X-Plex-Token=' . $_SESSION['plexServerToken'];
            write_log("Actorsections url: " . $url);
            $result = curlGet($url);
        }
		if ($result) {
			$container = new SimpleXMLElement($result);
			write_log("Trying to find an actor named ".ucwords(trim($actor)));
			foreach ($container->children() as $actors) {
				if ($actors['title'] == ucwords(trim($actor))) {
					$actorKey = $actors['fastKey'];
					write_log("Actor found: ". $actors['title']);
				}
			}
			if (!($actorKey)) {
				write_log("No actor key found, I should be done now.");
				return false;
			}
		} else {
			write_log("No result found, I should be done now.");
			return false;
		}

		$url = $_SESSION['plexServerUri']. $actorKey. '&X-Plex-Token=' . $_SESSION['plexServerToken'];
		write_log("I have an actor key, and now a URL: ". $url);

		$result = curlGet($url);
		if ($result) {
			$matches = array();
			$container = new SimpleXMLElement($result);
			foreach ($container->children() as $video) {
				array_push($matches,$video);
			}
			$size = sizeof($matches);
			if ($size > 0) {
				$winner = rand(0,$size);
				$winner = $matches[$winner];
				write_log("WE GOT A WINNER!! ". $winner['title']);
				return $winner;
			}
		}
		return false;
	}


	// Send some stuff to a play queue
	function queueMedia($media, $audio=false, $queueID=false) {
		write_log("Function Fired.");
		write_log("Media array: ".json_encode($media));
		$key = $media['key'];
		$url = $_SESSION['plexServerUri'].$key.'?X-Plex-Token='.$_SESSION['plexServerToken'];
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			$media = $container;
		}
		write_log("Media Section UUID is " .$media['librarySectionUUID']);
		write_log("Media key is " .$key);
		$key = urlencode($key);
		$uri = 'library://'.$media['librarySectionUUID'].'/item/'.$key;
		write_log("Media URI is " .$uri);
		$uri = urlencode($uri);
		$_SESSION['serverHeader'] = '&X-Plex-Client-Identifier='.$_SESSION['plexClientId'].
		'&X-Plex-Token='.$_SESSION['plexToken'];
		write_log("Encoded media URI is " .$uri);
		$url = $_SESSION['plexServerUri'].'/playQueues'.($queueID ? '/'.$queueID : '').'?type='.($audio ? 'audio' : 'video').
		'&uri='.$uri.
		'&shuffle=0&repeat=0&includeChapters=1&'.($audio ? 'includeRelated' : 'continuous').'=1'.
		$_SESSION['plexHeader'];
        $headers = clientHeaders();
        write_log("QueueMedia Url: ".protectURL($url));
		$result = curlPost($url,false,false,$headers);
		if ($result) {
			$container = new SimpleXMLElement($result);
			$container = json_decode(json_encode($container),true);
			$queueID = $container['@attributes']['playQueueID'] ?? false;
			write_log("Retrieved queue ID of ".$queueID);
		}
        return $queueID;
	}

	function queueAudio($media) {
	    write_log("Function fired.");
	    write_log("MEDIA: ".json_encode($media));
	    $array = $artistKey = $id = $response = $result = $song = $url = $uuid = false;
	    $sections = fetchSections();
	    foreach($sections as $section) if ($section['type'] == "artist") $uuid = $section['uuid'];
	    $ratingKey = (isset($media['ratingKey']) ? urlencode($media['ratingKey']) : false);
        $key = (isset($media['key']) ? urlencode($media['key']) : false);
        $uri = urlencode('library://'.$uuid.'/item/'.$key);
        write_log("Media URI is " .$uri);

        $type = $media['type'] ?? false;
	    if (($key) && ($type) && ($uuid)) {
            write_log($type." found for queueing.");
            $url = $_SESSION['plexServerUri'] . "/playQueues?type=audio&uri=library%3A%2F%2F".$uuid."%2F";
	        switch($type) {
                case 'album':
                    $url .= "item%2F%252Flibrary%252Fmetadata%252F" . $ratingKey . "&shuffle=0";
                    $artistKey = $media['parentRatingKey'];
                    break;
                case 'artist':
                    $url .= "item%2F%252Flibrary%252Fmetadata%252F" . $ratingKey . "&shuffle=0";
                    $artistKey = $media['ratingKey'];
                    break;
                case 'track':
                    $artistKey = $media['grandparentRatingKey'];
                    $url .= "directory%2F%252Fservices%252Fgracenote%252FsimilarPlaylist%253Fid%253D" . $ratingKey . "&shuffle=0";
                    break;
                default:
                    write_log("NOT A VALID AUDIO ITEM!","ERROR");
                    return false;
            }
	    }

	    if ($url) {
            $url .= "&repeat=0&includeChapters=1&includeRelated=1" . $_SESSION['plexHeader'];
            write_log("URL is ".protectURL(($url)));
            $result = curlPost($url);
        }

        if ($result) {
            $container = new SimpleXMLElement($result);
            $array = json_decode(json_encode($container), true);
            write_log("Queue ID of " . $array['@attributes']['playQueueID']);
            $id = $array['@attributes']['playQueueID'] ?? false;
            if (($id) && isset($_SESSION['queueID'])) {
            	write_log("We have an ID and save queueID: ".$id." versus ".$_SESSION['queueID']);
            	if ($id == $_SESSION['queueID']) {
		            $url = $_SESSION['plexServerUri'] . '/player/playback/refreshPlayQueue?playQueueID=' . $id . '&X-Plex-Token=' . $_SESSION['plexServerToken'];
		            $result = curlGet($url);
		            write_log("RefreshResult: " . $result);
	            }
            }
        }
        if ($id) $_SESSION['queueID'] = $id;
        if (($id) && ($array)) {
	    	$song = $array['Track'][0]['@attributes'] ?? false;
            write_log("Song: ".json_encode($song));
        }
        if ($id && $song) {
	        $response = $song;
	        $response['queueID'] = $id;
	    }
	    write_log("Final response: ".json_encode($response));
	    if ($artistKey) {
		    $extraURL = $_SESSION['plexServerUri'] . '/library/metadata/' . $artistKey . "?X-Plex-Token=" . $_SESSION['plexServerToken'];
		    write_log("Extras URL is " . $extraURL);
		    $extra = curlGet($extraURL);
		    if ($extra) {
			    $extra = new SimpleXMLElement($extra);
			    $extra = json_decode(json_encode($extra), true)['Directory']['@attributes'];
			    $response['summary'] = $extra['summary'];
			    write_log("Media Extra: " . json_encode($extra));
		    }
	    }
        return $response;
    }

	function playMedia($media) {
		if (isset($media['key'])) {
		    $clientProduct = $_SESSION['plexClientProduct'];
			switch ($clientProduct) {
				case 'cast':
                    $result = playMediaCast($media);
					break;
				case (preg_match('/Roku/', $clientProduct) ? $clientProduct : !$clientProduct):
				case 'PlexKodiConnect':
					$result = playMediaRelayed($media);
					break;
				case 'Plex for Android':
				    $result = (isset($media['queueID']) ? playMediaQueued($media) : playMediaDirect($media));
					break;
				case 'Plex Media Player':
				case 'Plex Web':
				case 'Plex TV':
				default:
					$result = playMediaQueued($media);
					break;
			}
			return $result;
		} else {
			write_log("No media to play!!","ERROR");
			$result['status'] = 'error';
			return $result;
		}
	}


	function playMediaDirect($media) {
		write_log("Function Fired.");
		$serverID = $_SESSION['plexServerId'];
		$client = $_SESSION['plexClientUri'];
		$server = parse_url($_SESSION['plexServerUri']);
		$serverProtocol = $server['scheme'];
		$serverIP = $server['host'];
		$serverPort =$server['port'];
		$transientToken = fetchTransientToken();
		$playUrl = $client.'/player/playback/playMedia'.
		'?key='.urlencode($media['key']) .
		'&offset='.($media['viewOffset'] ?? 0).
		'&machineIdentifier=' .$serverID.
		'&protocol='.$serverProtocol.
		'&address=' .$serverIP.
		'&port=' .$serverPort.
		'&path='.urlencode($_SESSION['plexServerUri'].'/'.$media['key']).
		'&X-Plex-Target-Client-Identifier='.$_SESSION['plexClientId'].
		'&token=' .$transientToken;
		$status = playerCommand($playUrl);
		write_log('Playback URL is ' . protectURL($playUrl));
		$result['url'] = $playUrl;
		$result['status'] = $status['status'];
		return $result;
	}

	function playMediaRelayed($media) {
		write_log("Function Fired.");
		$server = parse_url($_SESSION['plexServerUri']);
		$serverProtocol = $server['scheme'];
		$serverIP = $server['host'];
		$serverPort =$server['port'];
		$serverID = $_SESSION['plexServerId'];
		$queueID = (isset($media['queueID']) ? $media['queueID'] : queueMedia($media));
		$transientToken = fetchTransientToken();
		$_SESSION['counter']++;
		write_log("Current command ID is " . $_SESSION['counter']);
		write_log("Queue Token is ".$queueID);
		$playUrl = $_SESSION['plexServerUri'].'/player/playback/playMedia'.
			'?key='.urlencode($media['key']) .
			'&offset='.($media['viewOffset'] ?? 0).
			'&machineIdentifier=' .$serverID.
			'&protocol='.$serverProtocol.
			'&address=' .$serverIP.
			'&port=' .$serverPort.
			'&containerKey=%2FplayQueues%2F'.$queueID.'%3Fown%3D1%26window%3D200'.
			'&token=' .$transientToken.
			clientString().
			'&X-Plex-Token=' .$_SESSION['plexServerToken'].
			'&commandID='.$_SESSION['counter'];
		$headers = clientHeaders();
		$result = curlGet($playUrl,$headers);
		write_log('Playback URL is ' . protectURL($playUrl));
		write_log("Result value is ".$result);
		$status = (((preg_match("/200/",$result) && (preg_match("/OK/",$result))))?'success':'error');
		write_log("Result is ".$status);
		$return['url'] = $playUrl;
		$return['status'] = $status;
		return $return;
	}


	function playMediaQueued($media) {
		write_log("Function Fired.");
		$server = parse_url($_SESSION['plexServerUri']);
		$serverProtocol = $server['scheme'];
		$serverIP = $server['host'];
		$serverPort =$server['port'];
		$serverID = $_SESSION['plexServerId'];
		$queueID = (isset($media['queueID']) ? $media['queueID'] : queueMedia($media));
		$transientToken = fetchTransientToken();
		$_SESSION['counter']++;
		write_log("Current command ID is " . $_SESSION['counter']);
		write_log("Queue Token is ".$queueID);
		$playUrl = $_SESSION['plexClientUri'].'/player/playback/playMedia'.
		'?key='.urlencode($media['key']) .
		'&offset='.($media['viewOffset'] ?? 0).
		'&machineIdentifier=' .$serverID.
		'&protocol='.$serverProtocol.
		'&address=' .$serverIP.
		'&port=' .$serverPort.
		'&containerKey=%2FplayQueues%2F'.$queueID.'%3Fown%3D1%26window%3D200'.
		'&token=' .$transientToken.
		'&commandID='.$_SESSION['counter'];
        $headers = clientHeaders();
        $result = curlGet($playUrl,$headers);
		write_log('Playback URL is ' . protectURL($playUrl));
		write_log("Result value is ".$result);
		$status = (((preg_match("/200/",$result) && (preg_match("/OK/",$result))))?'success':'error');
		write_log("Result is ".$status);
		$return['url'] = $playUrl;
		$return['status'] = $status;
		return $return;

	}

	function playMediaCast($media) {
		write_log("Function fired.");
        write_log("Media: ".json_encode($media));
        //Set up our variables like a good boy
        $key = $media['key'];
		$machineIdentifier = $_SESSION['deviceID'];
		$server = parse_url($_SESSION['plexServerUri']);
		$serverProtocol = $server['scheme'];
		$serverIP = $server['host'];
		$serverPort =$server['port'];
		$userName = $_SESSION['plexUserName'];
		$transcoderVideo = ($media['type'] != 'track');
        $queueID = (isset($media['queueID']) ? $media['queueID'] : queueMedia($media));
        $transientToken = fetchTransientToken();

        write_log("Got queued media, continuing.");

		// Set up our cast device
		$client = parse_url($_SESSION['plexClientUri']);
		write_log("Client: ".json_encode($client));
		$cc = new Chromecast($client['host'],$client['port']);
		if ($cc) {
			write_log("We have a CC");
			// Build JSON
			$result = ['type' => 'LOAD', 'requestId' => $cc->requestId, 'media' => ['contentId' => (string)$key, 'streamType' => 'BUFFERED', 'contentType' => ($transcoderVideo ? 'video' : 'music'), 'customData' => ['offset' => ($media['viewOffset'] ?? 0), 'directPlay' => true, 'directStream' => true, 'subtitleSize' => 100, 'audioBoost' => 100, 'server' => ['machineIdentifier' => $machineIdentifier, 'transcoderVideo' => $transcoderVideo, 'transcoderVideoRemuxOnly' => false, 'transcoderAudio' => true, 'version' => '1.4.3.3433', 'myPlexSubscription' => true, 'isVerifiedHostname' => true, 'protocol' => $serverProtocol, 'address' => $serverIP, 'port' => $serverPort, 'accessToken' => $transientToken, 'user' => ['username' => $userName,], 'containerKey' => $queueID . '?own=1&window=200',], 'autoplay' => true, 'currentTime' => 0,]]];
			// Launch and play on Plex
			write_log("JSON Message: " . json_encode($result));
			$cc->Plex->play(json_encode($result));
			write_log("Sent play request.");
			sleep(1);
			fclose($cc->socket);
			$return['status'] = 'success';
		} else {
			$return['status'] = 'error';
		}
		$return['url'] = 'chromecast://' . $client['host'] . ':' . $client['port'];
		write_log("Returning something: ".json_encode($return));
		return $return;

	}

	function castStatus() {
		$addresses = parse_url($_SESSION['plexClientUri']);
		$url = $_SESSION['plexServerUri'].'/status/sessions/?X-Plex-Token='.$_SESSION['plexServerToken'];
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			$status = array();
            if (isset($container->Video)) {
                foreach ($container->Video as $Video) {
                    $vidArray = json_decode(json_encode($Video),true);
                    $isCast = ($vidArray['Player']['@attributes']['address'] == $addresses['host']);
                    $isPlayer = ($vidArray['Player']['@attributes']['machineIdentifier'] == $_SESSION['plexClientId']);
                    if (($isPlayer) || ($isCast)) {
                        $status['status'] = $vidArray['Player']['@attributes']['state'];
                        $time=$vidArray['TranscodeSession']['@attributes']['progress'];
                        $duration = $vidArray['TranscodeSession']['@attributes']['duration'];
                        $status['time'] = $duration / $time;
                        $status['plexServerId']=$_SESSION['plexServerUri'];
                        $status['mediaResult'] = $vidArray;
                        $thumb = (($vidArray['@attributes']['type'] == 'movie') ? $vidArray['@attributes']['thumb'] : $vidArray['@attributes']['parentThumb']);
                        $art = $vidArray['@attributes']['art'];
                        $thumb = $_SESSION['plexServerPublicUri'].$thumb."?X-Plex-Token=".$_SESSION['plexServerToken'];
                        $art = $_SESSION['plexServerPublicUri'].$art."?X-Plex-Token=".$_SESSION['plexServerToken'];
                        $status['mediaResult']['thumb'] = $thumb;
                        $status['mediaResult']['art'] = $art;
                        // Progress seems to go to 100 and then stop. Use the chromecast reporting to fill in the actual time.
						$addresses = explode(":",$_SESSION['plexClientUri']);
						$client = parse_url($_SESSION['plexClientUri']);
						try {
							$cc = new Chromecast($client['host'],$client['port']);
							$r = $cc->Plex->plexStatus();
							fclose($cc->socket);
							$r = str_replace('\"','"',$r);
							preg_match("/\"currentTime\"\:([^\,]*)/s",$r,$m);
							$currentTime = $m[1];
							if ($currentTime == "") { $currentTime = 0; }
							$status['time'] = $currentTime * 1000;
							//write_log("CASTSTATUS: (M) : " . $currentTime . " - " . $duration);
						} catch (Exception $e) {
						// If the chromecast doesn't answer then it doesn't matter so much!
						}
                    }
                }
            }
		} else {
			$status['status'] = 'error';
		}
		$status = json_encode($status);
                //write_log("CASTSTATUS: " . $status);
		return $status;
	}

	function playerStatus($wait=0) {
		if ($_SESSION['plexClientProduct'] == 'cast') {
			return castStatus();
		} else {
			$url = $_SESSION['plexClientUri'].
			'/player/timeline/poll?wait='.$wait.'&commandID='.$_SESSION['counter'];
            $headers = clientHeaders();
			$results = curlPost($url,false,false,$headers);
            write_log("Player url is ".protectURL($url).".");
			$status = array();
			if ($results) {
				$container = new SimpleXMLElement($results);
				$array = json_decode(json_encode($container),true);
				if (count($array)) {
					$status['status'] = 'stopped';
                        foreach($array['Timeline'] as $item) {
                            $Timeline = $item['@attributes'];

                            if ((($Timeline['state'] == 'playing') || ($Timeline['state'] == 'paused')) && ($Timeline['key'])) {
	                            $uri = $Timeline['protocol'].'://'.$Timeline['address'].':'.$Timeline['port'];
	                            $token= $Timeline['token'];
                                    $mediaURL = $uri . $Timeline['key'] .
                                        '?X-Plex-Token=' . $token;
                                    $media = curlGet($mediaURL);
                                    if ($media) {
                                        $mediaContainer = new SimpleXMLElement($media);
                                        $MC = json_decode(json_encode($mediaContainer), true);
                                        $item = (isset($MC['Video']) ? $MC['Video']['@attributes'] : $MC['Track']['@attributes']);
                                        $extras = (($item['type'] === 'track') ? fetchMediaExtra(($item['grandparentRatingKey'])) : false);
                                        if ($extras) $item['summary'] = $extras['summary'];
                                        $status['mediaResult'] = $item;
                                        $seriesThumb = (isset($item['parentThumb']) ? $item['parentThumb'] : $item['grandparentThumb']);
                                        $thumb = (($item['type'] === 'episode') ? $seriesThumb : $item['thumb']);
                                        #TODO: Get the public address of the server it's playing on, not the one we have selected.
	                                    $thumb = transcodeImage($thumb,$uri,$token);
                                        $status['mediaResult']['thumb'] = $thumb;
                                        $art = (isset($item['art']) ? $item['art'] : false);
                                        if ($art) {
                                            $art = transcodeImage($art,$uri,$token);
                                            $status['mediaResult']['art'] = $art;
                                        }
                                        $status['status'] = (string)$Timeline['state'];
                                        $status['volume'] = (string)$Timeline['volume'];
                                        if ($Timeline['time']) {
                                            $status['time'] = (string)$Timeline['time'];
                                        }
                                    }

                            }
                        }

				}
			}
		}
		if (! $status) {
			$status['status'] = 'error';
		}
		//write_log("Final Status: ".json_encode($status));
		return json_encode($status);
	}

	function sendCommand($cmd) {
			write_log("Function fired!");
			$clientProduct = $_SESSION['plexClientProduct'];
			switch ($clientProduct) {
				case 'cast':
					$result = castCommand($cmd);
					break;
				case (preg_match('/Roku/', $clientProduct) ? $clientProduct : !$clientProduct):
				case 'PlexKodiConnect':
					$result = relayCommand($cmd);
					break;
				default:
					$url = $_SESSION['plexClientUri'].'/player/playback/'. $cmd . ((strstr($cmd, '?')) ? "&" : "?").'X-Plex-Token=' .$_SESSION['plexToken'];
					$result = playerCommand($url);
					break;
			}
			write_log("Result is ".print_r($result,true));
			return $result;
	}

	function relayCommand($cmd) {
		write_log("Function fired");
		$url = $_SESSION['plexServerUri'].'/player/playback/'.$cmd.'?type=video&commandID='.$_SESSION['counter'].
			clientString().'&X-Plex-Token='.$_SESSION['plexServerToken'];
		write_log("Relay URL: ".$url);
		$result = curlGet($url);
		write_log("Result: ".$result);

		return $result;
	}

	function playerCommand($url) {
		if (!(preg_match('/http/',$url))) $url = $_SESSION['plexClientUri'].$url;
		$status = 'success';
		write_log("Function Fired.");
		$_SESSION['counter']++;
		write_log("Current command ID is " . $_SESSION['counter']);
		$url .='&commandID='.$_SESSION['counter'];
        $headers = clientHeaders();
        $container = curlPost($url,false,false,$headers);
		write_log("Command response is ".$container);
		if (preg_match("/error/",$container)) {
			write_log('Request failed, HTTP status code: ' . $status,"ERROR");
			$status = 'error';
		} else {
			$status = 'success';
		}
		write_log('URL is: '.protectURL($url));
		write_log("Status is " . $status);
		$return['url'] = $url;
		$return['status'] = $status;
		return $return;

	}

	function castCommand($cmd) {
	    write_log("Function fired.");
		$int = 100;
		// Set up our cast device
		if (preg_match("/volume/", $cmd)) {
			write_log("Precommand: " . $cmd);
			$int = filter_var($cmd, FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION);
			$cmd = "volume";
			write_log("Post: ".$cmd." Int: ".$int);
		}
		$client = parse_url($_SESSION['plexClientUri']);
		$cc = new Chromecast($client['host'],$client['port']);

		$valid = true;
		write_log("Received CAST Command: " . $cmd);
		switch ($cmd) {
			case "play":
				$cc->Plex->restart();
				break;
			case "pause":
				$cc->Plex->pause();
				break;
			case "stepForward":
				$cc->Plex->stepForward();
				break;
			case "stop":
				$cc->Plex->stop();
				break;
			case "skipBack":
				$cc->Plex->skipBack();
				break;
			case "skipForward":
            case "skipNext":
				$cc->Plex->skipForward();
				break;
			case "volume":
				write_log("Should be a volume command.");
				$cc->Plex->SetVolume($int);
				break;
			default:
				$return['status'] = 'error';
				$valid = false;

		}
		fclose($cc->socket);
		sleep(1);

		if ($valid) {
			$return['url'] = "No URL";
			$return['status'] = 'success';
            return $return;
		}
		$return['status'] = 'error';
		return $return;
	}


	// This should take our command objects and save them to the JSON file
	// read by the webUI.
	function log_command($resultObject) {
		// Decode our incoming command, append a timestamp.
		$newCommand = json_decode($resultObject,true);

		// Check for our JSON file and make sure we can access it
		$filename = "commands.php";
		$handle = fopen($filename, "r");
		//Read first line, but do nothing with it
		fgets($handle);
		$contents = '[';
		//now read the rest of the file line by line, and explode data
		while (!feof($handle)) {
			$contents .= fgets($handle);
		}

		// Read contents into an array
		$jsondata = $contents;
		$json_a = json_decode($jsondata);
		if (empty($json_a)) $json_a = array();

		// Append our newest command to the beginning
		array_unshift($json_a,$newCommand);

		// If we have more than 10 commands, remove one.
		if (count($json_a) >= 11) {
			array_pop($json_a);
		}

		// Triple-check we can write, write JSON to file
		if (!$handle = fopen($filename, 'wa+')) die;
		$cache_new = "'; <?php die('Access denied'); ?>";
		$cache_new .= json_encode($json_a, JSON_PRETTY_PRINT);
		if (fwrite($handle, $cache_new) === FALSE) die;
		fclose($handle);
		scanDevices();
		return $json_a;
	}

	function popCommand($id) {
		write_log("Function fired.");
		write_log("Popping ID of ".$id);
        // Check for our JSON file and make sure we can access it
        $filename = "commands.php";
        $handle = fopen($filename, "r");
        //Read first line, but do nothing with it
        fgets($handle);
        $contents = '[';
        //now read the rest of the file line by line, and explode data
        while (!feof($handle)) {
            $contents .= fgets($handle);
        }

        // Read contents into an array
        $jsondata = $contents;
        $json_a = json_decode($jsondata,true);
        unset($json_a[$id]);
        if (count($json_a)) write_log("JSON Exists.");
		write_log("New JSON: ".json_encode($json_a));
		// Triple-check we can write, write JSON to file
        if (!$handle = fopen($filename, 'wa+')) die;
        $cache_new = "'; <?php die('Access denied'); ?>";
        if (count($json_a)) {
            $cache_new .= json_encode($json_a, JSON_PRETTY_PRINT);
        }
        if (fwrite($handle, $cache_new) === FALSE) return false;
        fclose($handle);
        if (! count($json_a)) $json_a = [];
        return $json_a;
	}

	// Write and save some data to the webUI for us to parse
	// IDK If we need this anymore
	function metaTags() {
		$tags = '';
		$filename = "commands.php";
		$handle = fopen($filename, "r");
		//Read first line, but do nothing with it
		fgets($handle);
		$contents = '[';
		//now read the rest of the file line by line, and explode data
		while (!feof($handle)) {
			$contents .= fgets($handle);
		}
		$dvr = ($_SESSION['plexDvrUri'] ? "true" : "");
		if ($contents == '[') $contents = '';
		$commandData = urlencode($contents);
		$tags .= '<meta id="tokenData" data="' . $_SESSION['plexServerToken'] . '"/>' .
            '<meta id="usernameData" data="' . $_SESSION['plexUserName'] . '"/>' .
            '<meta id="publicIP" data="' . $_SESSION['publicAddress'] . '"/>' .
            '<meta id="deviceID" data="' . $_SESSION['deviceID'] . '"/>' .
            '<meta id="serverURI" data="' . $_SESSION['plexServerUri'] . '"/>' .
            '<meta id="clientURI" data="' . $_SESSION['plexClientUri'] . '"/>' .
            '<meta id="clientName" data="' . $_SESSION['plexClientName'] . '"/>' .
            '<meta id="plexDvr" enable="' . $dvr . '" uri="' . $_SESSION['plexDvrUri'] . '"/>' .
            '<meta id="rez" value="' . $_SESSION['plexDvrResolution'] . '"/>' .
            '<meta id="couchpotato" enable="' . $_SESSION['couchEnabled'] . '" ip="' . ($_SESSION['couchIP'] ?? "http://localhost") . '" port="' . $_SESSION['couchPort'] . '" auth="' . $_SESSION['couchAuth'] . '"/>' .
            '<meta id="sonarr" enable="' . $_SESSION['sonarrEnabled'] . '" ip="' . ($_SESSION['sonarrIP'] ?? "http://localhost") . '" port="' . ($_SESSION['sonarrPort'] ?? "8989") . '" auth="' . $_SESSION['sonarrAuth'] . '"/>' .
            '<meta id="sick" enable="' . $_SESSION['sickEnabled'] . '" ip="' . $_SESSION['sickIP'] . '" port="' . $_SESSION['sickPort'] . '" auth="' . $_SESSION['auth_sick'] . '"/>' .
            '<meta id="radarr" enable="' . $_SESSION['radarrEnabled'] . '" ip="' . ($_SESSION['radarrIP'] ?? "http://localhost") . '" port="' . $_SESSION['radarrPort'] . '" auth="' . $_SESSION['radarrAuth'] . '"/>' .
            '<meta id="ombi" enable="' . $_SESSION['ombiEnabled'] . '" ip="' . ($_SESSION['sickIP'] ?? "http://localhost") . '" port="' . $_SESSION['ombiPort'] . '" auth="' . $_SESSION['auth_ombi'] . '"/>' .
            '<meta id="logData" data="' . $commandData . '"/>';
		return $tags;
	}

	function logData() {
		write_log("Function fired");
		$filename = "commands.php";
		$handle = fopen($filename, "r");
		//Read first line, but do nothing with it
		fgets($handle);
		$contents = '[';
		//now read the rest of the file line by line, and explode data
		while (!feof($handle)) {
			$contents .= fgets($handle);
		}
		if ($contents == '[') $contents = '';
		$commandData = urlencode($contents);
		return '<meta id="logData" data="' . $commandData . '"/>';
	}

	function fetchAirings($days) {
		$scheduled = false;
		$list = [];
		$enableSick = ($_SESSION['sickEnabled']=="true");
		$enableSonarr = ($_SESSION['sonarrEnabled']=="true");
		$enableDvr = isset($_SESSION['plexDvrUri']);

		switch ($days) {
			case 'tomorrow':
				$startDate = new DateTime ('tomorrow');
				$endDate = new DateTime('tomorrow + 1day' );
				break;
			case 'weekend':
				$startDate = new DateTime('next Friday');
				$endDate = new DateTime('next Monday');
				break;
			case "Monday":
			case "Tuesday":
			case "Wednesday":
			case "Thursday":
			case "Friday":
			case "Saturday":
			case "Sunday":
				$endDate = new DateTime('next ' . $days . ' + 1day');
				$startDate = new DateTime('next ' . $days);
				break;
			case 'now':
			default:
				$startDate = new DateTime('today');
				$endDate = new DateTime('tomorrow');
				break;
		}
		$date2 = $endDate->format('Y-m-d');
		$date1 = $startDate->format('Y-m-d');
		write_log("Days: ".$days. " , Start: ".$date1." , Finish: ".$date2);

		if ($enableSick) {
			write_log("Sickrage is enabled.");
			$sick = new SickRage($_SESSION['sickIP'].':'.$_SESSION['sickPort'], $_SESSION['auth_sick']);
			$scheduled = json_decode($sick->future('date','soon'),true);
		}

		if ($enableSonarr) {
			write_log("Sonarr is enabled.");
			$sonarr = new Sonarr($_SESSION['sonarrIP'].':'.$_SESSION['sonarrPort'], $_SESSION['sonarrAuth']);
			$scheduled = json_decode($sonarr->getCalendar($date1,$date2),true);
		}

		if ($scheduled) {
			write_log("Scheduled: ".json_encode($scheduled));
			if (isset($scheduled['data']['soon'])) {
				$shows = $scheduled['data']['soon'];
				foreach($shows as $show) {
					$airDate = DateTime::createFromFormat('Y-m-d', $show['airdate']);
					if ($airDate >= $startDate && $airDate <= $endDate) {
						write_log("This fits: " . $show['show_name'] . ":" . $show['airdate']);
						$extra = fetchTVDBInfo($show['tvdbid']);
						$item = ['title' => $show['show_name'], 'summary' => $show['ep_plot'], 'year' => explode('-', $show['airdate'])['0'], 'thumb' => $extra['thumb'], 'airdate' => $show['airdate']];
						array_push($list, $item);
					}
				}
			} else {
				$shows = $scheduled;
				foreach ($shows as $show) {
					$extra = fetchTVDBInfo($show['tvdbId']);
					$item = ['title'=>$show['series']['title'],
						'summary'=>$show['overview'],
						'year'=>$show['series']['year'],
						'thumb'=>$extra['thumb']];
					array_push($list,$item);
				}
			}
		}

		if ($enableDvr) {
			$url = $_SESSION['plexDvrUri']."/media/subscriptions/scheduled?X-Plex-Token=".$_SESSION['plexDvrToken'];
			write_log("DVR URL: ".protectURL($url));
			$scheduledContainer = curlGet($url);
			if ($scheduledContainer) {
				$scheduledContainer = new SimpleXMLElement($scheduledContainer);
				$scheduled = json_decode(json_encode($scheduledContainer),true)['MediaGrabOperation'];
				foreach($scheduled as $showItem) {
					if ($showItem['@attributes']['status']==='scheduled') {
						$show = $showItem['Video']['@attributes'];
						$date = (isset($showItem['Video']['Media'][0]['@attributes']['beginsAt']) ? $showItem['Video']['Media'][0]['@attributes']['beginsAt'] : false);
						if (! $date) $date = (isset($showItem['Video']['Media']['@attributes']['beginsAt']) ? $showItem['Video']['Media']['@attributes']['beginsAt'] : false);
						if ($date) {
							$airDate = new DateTime("@$date");
							if ($airDate >= $startDate && $airDate <= $endDate) {
								$item = ['title' => $show['grandparentTitle'], 'summary' => $show['summary'], 'year' => $show['year'], 'thumb' => $show['grandparentThumb'], 'airdate' => $date];
								array_push($list, $item);
							}
						} else write_log("Unable to parse media date, tell your developer.","ERROR");
					}
				}
				write_log("DVR Scheduled: ".json_encode($scheduled));
			}
		}
		write_log("Final list: ".json_encode($list));
		return (count($list) ? $list : false);
	}


	function downloadSeries($command,$season=false,$episode=false) {
		$enableSick = $_SESSION['sickEnabled'];
		$enableSonarr = $_SESSION['sonarrEnabled'];

		if ($enableSonarr == 'true') {
			write_log("Using Sonarr for Episode agent");
			$response = sonarrDownload($command,$season,$episode);
			return $response;
		}

		if ($enableSick == 'true') {
			write_log("Using Sick for Episode agent");
			$response = sickDownload($command,$season,$episode);
			return $response;
		}
		return "No downloader";
	}

	function sickDownload($command,$season=false,$episode=false) {
		write_log("Function fired");
		$exists = $id = $response = $responseJSON = $resultID = $resultYear = $status = $results = $result = $show = false;
		$sickURL = $_SESSION['sickIP'];
		$sickApiKey = $_SESSION['auth_sick'];
		$sickPort = $_SESSION['sickPort'];
        $highest = 69;
		$sick = new SickRage($sickURL.':'.$sickPort, $sickApiKey);
        $results = json_decode($sick->shows(), true)['data'];
        foreach ($results as $show) {
            if (cleanCommandString($show['show_name']) == cleanCommandString($command)) {
                write_log("Found it in the library already.");
                $exists = true;
                $results = false;
                $result = $show;
                $status = 'SUCCESS: Already in searcher.';
                break;
            }
        }

		if (! $result) {
            write_log("Not in library, searching TVDB.");
            $results = $sick->sbSearchTvdb($command);
            $responseJSON = json_decode($results, true);
            $results = $responseJSON['data']['results'];
        }

        if ($results) {
            foreach ($results as $searchResult) {
                write_log("Search result: " . json_encode($searchResult));
                $resultName = ($exists ? (string)$searchResult['show_name'] : (string)$searchResult['name']);
                $cleaned = cleanCommandString($resultName);
                if ($cleaned == $command) {
                    $result = $searchResult;
                    write_log("This is an exact match: " . $resultName);
                    break;
                } else {
                    $score = similarity($command, $cleaned) * 100;
                    write_log("Similarity between results is " . similarity($command, $cleaned) * 100);
                    if ($score > $highest) {
                        write_log("This is the highest matched result so far.");
                        $highest = $score;
                        $result = $searchResult;
                    }
                }
            }
		}

        if (($result) && isset($result['tvdbid'])) {
            $id = $result['tvdbid'];
            $show = fetchTVDBInfo($id);
            $show['type'] = 'show';
        } else {
            $status = 'ERROR: No results.';
        }

        if ((!$exists) && ($result) && ($id)) {
            write_log("Show not in list, adding.");
            $result = $sick->showAddNew($id,null,'en',null,'wanted',$_SESSION['sickProfile']);
            $responseJSON = json_decode($result, true);
            write_log('Fetch result: ' . $result);
            $status = strtoupper($responseJSON['result']).': '.$responseJSON['message'];
        }

        if ($season) {
            if ($episode) {
                write_log("And an episode. " . $episode);
                $result = $sick->episodeSearch($id, $season, $episode);
                if ($result) {
                    unset($responseJSON);
                    write_log("Episode search worked, result is " . $result);
                    $responseJSON = json_decode($result, true);
                    $resultYear = (string)$responseJSON['data']['airdate'];
                    $resultYearArray = explode("-", $resultYear);
                    $resultYear = $resultYearArray[0];
                }
            }
        }
        write_log("Show so far: ".json_encode($show));
        $response['status'] = $status;
        $response['mediaResult'] = $show;
        if ($resultYear) $response['mediaResult']['year'] = $resultYear;
		return $response;
	}

	function sonarrDownload2($command,$season=false,$episode=false) {
        write_log("Function fired.");
        $exists = $score = $show = false;
        $sonarrURL = $_SESSION['sonarrIP'];
        $sonarrApiKey = $_SESSION['sonarrAuth'];
        $sonarrPort = $_SESSION['sonarrPort'];
        $sonarr = new Sonarr($sonarrURL.':'.$sonarrPort, $sonarrApiKey);
        $rootArray = json_decode($sonarr->getRootFolder(),true);
        $seriesArray = json_decode($sonarr->getSeries(),true);
        $root = $rootArray[0]['path'];

        // See if it's already in the library
        foreach($seriesArray as $series) {
            if(cleanCommandString($series['title']) == cleanCommandString($command)) {
                write_log("This show is already in the library: ".json_encode($series));
                $exists = $show = $series;
                break;
            }
        }

        // If not, look for it.
        if (! $exists) {
            $search = json_decode($sonarr->getSeriesLookup($command),true);
            write_log("Searching for show, array is ".json_encode($search));
            foreach($search as $series) {
                $similarity = similarity(cleanCommandString($command),cleanCommandString($series['title']));
                write_log("Series title is ".$series['title'].", similarity is ".$similarity);
                if ($similarity > $score) $score = $similarity;
                if ($similarity > .7) $show = $series;
            }
            // If we found something to download and don't have it, add it.
            if (is_array($show)) {
                $show['qualityProfileId'] = ($_SESSION['sonarrProfile'] ? $_SESSION['sonarrProfile'] : 0);
                $show['rootFolderPath'] = $root;
                write_log("Attempting to add the series ".$show['title']." JSON is: ".json_encode($show));
                $result = $sonarr->postSeries($show, false);
                write_log("Show add result: ".$result);
            }
        }




        // Now that we know it's in the library, check for episode/season search.
        if ($season || $episode) {
            if ($episode == -1) {

            }
        }
    }
	// Fetch a series from Sonarr
	// Need to add a method to trigger it to search for all episodes, etc.
	function sonarrDownload($command,$season=false,$episode=false) {
		$response = false;
		$sonarrURL = $_SESSION['sonarrIP'];
		$sonarrApiKey = $_SESSION['sonarrAuth'];
		$sonarrPort = $_SESSION['sonarrPort'];
		$baseURL = $sonarrURL.':'.$sonarrPort.'/api';
		$searchString = '/series/lookup?term='.urlencode($command);
		$authString = '&apikey='.$sonarrApiKey;
		$searchURL = $baseURL.$searchString.$authString;
		$root = curlGet($baseURL.'/rootfolder?apikey='.$sonarrApiKey);
		if ($root) {
			$rootPathObj = json_decode($root,true);
			$rootObj = $rootPathObj[0];
			$rootPath = (string)$rootObj['path'];
			write_log("RootPath: ".$rootPath);
			write_log("Search URL is ".protectURL($searchURL));
		} else {
			write_log("Error retrieving root path.","ERROR");
			return false;
		}
		$seriesCollectionURL = $baseURL.'/series?apikey='.$sonarrApiKey;
		$seriesCollection = curlGet($seriesCollectionURL);
		if ($seriesCollection) {
			$seriesJSON = json_decode($seriesCollection,true);
		} else {
			write_log("Error retrieving current series data.","ERROR");
			return false;
		}
		$result = curlGet($baseURL.$searchString.$authString);
		if ($result) {
			$resultJSONS = json_decode($result,true);
			if (!(empty($resultJSONS))) {
				$resultJSON = $resultJSONS[0];
				$year = $resultJSON['year'];
				write_log("Result JSON is ".json_encode($resultJSON));
				$putURL = $baseURL.'/series'.'?apikey='.$sonarrApiKey;
				write_log("sending result for fetching, URL is ".protectURL($putURL));
				$resultObject['title'] = (string)$resultJSON['title'];
				$resultObject['tvdbId'] = (string)$resultJSON['tvdbId'];
				$resultObject['qualityProfileId'] = ($_SESSION['sonarrProfile'] ? $_SESSION['sonarrProfile'] : 0);
				$resultObject['titleSlug'] = (string)$resultJSON['titleSlug'];
				$resultObject['images'] = $resultJSON['images'];
				$resultObject['summary'] = (string)$resultJSON['overview'];
				$seasons = array();
				foreach ($resultJSON['seasons'] as $season) {
					$monitored = (($season['seasonNumber'] == 0) ? false : true);
					array_push($seasons,array('seasonNumber'=>$season['seasonNumber'],'monitored'=>$monitored));
				}
				$resultObject['seasons'] = $seasons;
				$resultObject['monitored'] = true;
				$resultObject['titleSlug'] = (string)$resultJSON['titleSlug'];
				$resultObject['rootFolderPath'] = $rootPath;
				$resultObject['addOptions']['ignoreEpisodesWithFiles'] = false;
				$resultObject['addOptions']['searchForMissingEpisodes'] = true;
				$resultObject['addOptions']['ignoreEpisodesWithoutFiles'] = false;
				foreach($seriesJSON as $series) {
					if ($series['title'] == $resultObject['title']) {
						write_log("Results match: ".$resultObject['title']);
						$response['status'] = 'SUCCESS: Already in searcher.';
						$response['mediaResult']['@attributes']['url'] = $putURL;
						$resultObject['year'] = $year;
						$resultObject['summary'] = $resultJSON['overview'];
						$resultObject['type'] = 'show';
						$artUrl = $sonarrURL.':'.$sonarrPort.'/MediaCover/'. $series['id'] . '/fanart.jpg?apikey='.$sonarrApiKey;
                        $thumbUrl = $sonarrURL.':'.$sonarrPort.'/MediaCover/'. $series['id'] . '/poster.jpg?apikey='.$sonarrApiKey;
						$resultObject['art'] = cacheImage($artUrl);
						$resultObject['thumb'] = $thumbUrl;
						$response['mediaResult'] = $resultObject;
						$scanURL = $baseURL . "/command/SearchSeries?apikey=".$sonarrApiKey;
						$searchArray = ['name'=>"SearchSeries",'seriesId'=>$resultObject['tvdbId']];
						curlPost($scanURL,json_encode($searchArray),true);
						return $response;
					}
				}
				write_log("Made it to the next CURL");
				$content = json_encode($resultObject);
				write_log("Request content format: ".$content);
				$json_response = curlPost($putURL,$content,true);
				write_log("Add Command Successful!  Response is ".$json_response);
				$responseJSON = json_decode($json_response, true);
				if ($responseJSON) {
					$response['status'] = 'SUCCESS: Media added to library.';
					$response['mediaResult']['url'] = $putURL;
					$responseJSON['type'] = 'show';
					$responseJSON['year'] = $year;
					$seriesID = $responseJSON['id'];
					$tvdbId = $responseJSON['tvdbId'];
					$extras = fetchTVDBInfo($tvdbId);
					$responseJSON['art'] = $extras['thumb'];
					$responseJSON['summary'] = $extras['summary'];
					$responseJSON['subtitle'] = $extras['subtitle'];
					$response['mediaResult'] = $responseJSON;
					$scanURL = $baseURL.'/command'.'?apikey='.$sonarrApiKey;
					$fetchMe = array();
					$fetchMe['name'] = 'SeriesSearch';
					$fetchMe['seriesId'] = $seriesID;
					$curl = curl_init($scanURL);
					curl_setopt($curl, CURLOPT_HEADER, false);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt ($curl, CURLOPT_CAINFO, dirname(__FILE__) . "/cert/cacert.pem");
					curl_setopt($curl, CURLOPT_HTTPHEADER,
							array("Content-type: application/json"));
					curl_setopt($curl, CURLOPT_POST, true);
					curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fetchMe));
					curl_exec($curl);
					curl_close($curl);


				} else {
					$response['status'] = 'ERROR: No results.';
				}
			}
		}
		if ($season || $episode) {
			write_log("Looking for a season or episode fetch here.");
		}
        return $response;
	}

	function fetchTVDBInfo($id) {
		write_log("Function fired!");
	    $result = false;
        $apikey = curlPost('https://api.thetvdb.com/login','{"apikey": "19D9A181DA722C87"}',true);
        if ($apikey) $apikey = json_decode($apikey,true);
        if (isset($apikey['token'])) {
            $apikey = $apikey['token'];
            $url = 'https://api.thetvdb.com/series/'.$id;
            $show = curlGet($url,['Authorization: Bearer '.$apikey]);
            $url = 'https://api.thetvdb.com/series/'.$id.'/images/query?keyType=fanart';
            $images = curlGet($url,['Authorization: Bearer '.$apikey]);
            if ($show) {
            	write_log("Found a show!");
                $show = json_decode($show,true)['data'];
                $result['title'] = $show['seriesName'];
                $result['summary'] = $show['overview'];
                $result['subtitle'] = $show['siteRating']. ' - '.$show['status'];
                $result['year'] = explode('-',$show['firstAired'])[0];
            }
            if ($images) {
                $score = 0;
                $images = json_decode($images,true)['data'];
                foreach($images as $image) {
                    if ($image['ratingInfo']['average'] >= $score) $result['thumb'] = 'http://thetvdb.com/banners/'.$image['fileName'];
                }
            }
        }
        write_log("Returning: ".json_encode($result));
        return $result;
    }

	// Fetch a movie from CouchPotato
	function downloadMovie($command) {
		write_log("Function fired.");
		$enableOmbi = $_SESSION['ombiEnabled'];
		$enableCouch = $_SESSION['couchEnabled'];
		$enableRadarr = $_SESSION['radarrEnabled'];
		$response['status'] = "ERROR: No downloader configured.";
		if ($enableOmbi == 'true') {
			write_log("Using Ombi for Movie agent");
		}

		if ($enableCouch == 'true') {
			write_log("Using Couchpotoato for Movie agent");
			$response = couchDownload($command);
		}

		if ($enableRadarr == 'true') {
			write_log("Using Radarr for Movie agent");
			$response = radarrDownload($command);
		}
		return $response;
	}

	function couchDownload($command) {
		$couchURL = $_SESSION['couchIP'];
		$couchApikey = $_SESSION['couchAuth'];
		$couchPort = $_SESSION['couchPort'];
		$response = array();
		$response['initialCommand'] = $command;
		$response['parsedCommand'] = 'fetch the movie ' .$command;

		// Send our initial request to search the movie

		$url = $couchURL . ":" . $couchPort . "/api/" . $couchApikey . "/movie.search/?q=" . urlencode($command);
		write_log("Sending request to " . $url);
		$result = curlGet($url);

		// Parse the response, look for IMDB ID

		$body = json_decode($result,true);
		write_log("body:" .$result);
		$imdbID = (string)$body['movies'][0]['imdb'];

		// Now take the IMDB ID and send it with the title to Couchpotato
		if ($imdbID) {
			$title = $body['movies'][0]['titles'][0];
			$year = $body['movies'][0]['year'];
			$art = $body['movies'][0]['images']['backdrop_original'][0];
            $thumb = $art;
			write_log("Art URL should be ".$art);
			$plot = $body['movies'][0]['plot'];
			$subtitle = $body['movies'][0]['tagline'];
			write_log("imdbID: " . $imdbID);
			$resultObject['title'] = $title;
			$resultObject['year'] = $year;
			$resultObject['art'] = $art;
			$resultObject['thumb'] = $thumb;
			$resultObject['summary'] = $plot;
			$resultObject['subtitle'] = $subtitle;
			$resultObject['type'] = 'movie';
			$url2 = $couchURL . ":" . $couchPort . "/api/" . $couchApikey . "/movie.add/?identifier=" . $imdbID . "&title=" . urlencode($command).($_SESSION['couchProfile'] ? '&profile_id='.$_SESSION['couchProfile'] : '');
			write_log("Sending add request to: " . $url2);
			curlGet($url2);
			$response['status'] = 'SUCCESS: Media added successfully.';
			$response['mediaResult'] = $resultObject;
			$response['mediaResult']['url'] = $url2;
			return $response;
		} else {
			$response['status'] = 'ERROR: No results for query.';
			return $response;
		}
	}

	function radarrDownload($command) {
		$response = false;
		$radarrURL = $_SESSION['radarrIP'];
		$radarrApiKey = $_SESSION['radarrAuth'];
		$radarrPort = $_SESSION['radarrPort'];
		$baseURL = $radarrURL.':'.$radarrPort.'/api';

		$searchString = '/movies/lookup?term='.urlencode($command);
		$authString = '&apikey='.$radarrApiKey;
		$searchURL = $baseURL.$searchString.$authString;
		$root = curlGet($baseURL.'/rootfolder?apikey='.$radarrApiKey);
		if ($root) {
			$rootPathObj = json_decode($root,true);
			$rootObj = $rootPathObj[0];
			$rootPath = (string)$rootObj['path'];
			write_log("RootPath: ".$rootPath);
			write_log("Search URL is ".protectURL($searchURL));
		} else {
			write_log("Unable to fetch root path!");
			return false;
		}

		$movieCollectionURL = $baseURL.'/movie?apikey='.$radarrApiKey;
		$movieCollection = curlGet($movieCollectionURL);
		if ($movieCollection) {
			//write_log("Collection data retrieved: ".$movieCollection);
			$movieJSON = json_decode($movieCollection,true);
		} else {
			write_log("Unable to fetch current library info!");
			return false;
		}

		$result = curlGet($baseURL.$searchString.$authString);
		if ($result) {
			//write_log("Result is ".$result);
			$resultJSONS = json_decode($result,true);
			if (!(empty($resultJSONS))) {
				$resultJSON = $resultJSONS[0];
				write_log("Result JSON: ".json_encode($resultJSON));
				$putURL = $baseURL.'/movie'.'?apikey='.$radarrApiKey;
				write_log("sending result for fetching, URL is ".protectURL($putURL));
				unset($resultObject);
				$resultObject['title'] = (string)$resultJSON['title'];
				$resultObject['year'] = (string)$resultJSON['year'];
				$resultObject['tmdbId'] = (string)$resultJSON['tmdbId'];
				$resultObject['profileId'] = ($_SESSION['radarrProfile'] ? $_SESSION['radarrProfile'] : 0);
				$resultObject['qualityProfileId'] = ($_SESSION['radarrProfile'] ? $_SESSION['radarrProfile'] : 0);
				$resultObject['titleSlug'] = (string)$resultJSON['titleSlug'];
				$resultObject['images'] = $resultJSON['images'];
				$resultObject['monitored'] = true;
				$resultObject['titleSlug'] = (string)$resultJSON['titleSlug'];
				$resultObject['rootFolderPath'] = $rootPath;
				$resultObject['addOptions']['ignoreEpisodesWithFiles'] = false;
				$resultObject['addOptions']['searchForMovie'] = true;
				$resultObject['addOptions']['ignoreEpisodesWithoutFiles'] = false;
				foreach($movieJSON as $movie) {
					if ($movie['title'] == $resultObject['title']) {
						write_log("Results match: ".$resultObject['title']);
						$response['status'] = 'SUCCESS: Already in searcher.';
						$resultObject['url'] = $putURL;
						$resultObject['year'] = $resultJSON['year'];
						$resultObject['summary'] = $resultJSON['overview'];
						$resultObject['type'] = 'movie';
						$artUrl = $radarrURL.':'.$radarrPort.'/api/MediaCover/'. $movie['id'] . '/banner.jpg?apikey='.$radarrApiKey;
                        $thumbUrl = $radarrURL.':'.$radarrPort.'/api/MediaCover/'. $movie['id'] . '/poster.jpg?apikey='.$radarrApiKey;
						write_log("Art URL Should be ".$artUrl);
						$resultObject['art'] = cacheImage($artUrl);
						$resultObject['thumb'] = $thumbUrl;
						$response['mediaResult'] = $resultObject;
						return $response;
					}
				}
				write_log("Made it to the next CURL");
				$content = json_encode($resultObject);
				$curl = curl_init($putURL);
				curl_setopt($curl, CURLOPT_HEADER, false);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt ($curl, CURLOPT_CAINFO, dirname(__FILE__) . "/cert/cacert.pem");
				curl_setopt($curl, CURLOPT_HTTPHEADER,
						array("Content-type: application/json"));
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
				$json_response = curl_exec($curl);
				curl_close($curl);
				write_log("Add Command Successful!  Response is ".$json_response);
				$responseJSON = json_decode($json_response, true);
				if ($responseJSON) {
					$response['status'] = 'SUCCESS: Media added to searcher.';
					$response['mediaResult']['@attributes']['url'] = $putURL;
					$artUrl = $radarrURL.':'.$radarrPort.'/api/MediaCover/'. $responseJSON['id'] . '/banner.jpg?apikey='.$radarrApiKey;
					write_log("Art URL Should be ".$artUrl);
					$responseJSON['art'] = cacheImage($artUrl);
					$responseJSON['type'] = 'movie';
					$movieID = $responseJSON['id'];
					$response['mediaResult'] = $responseJSON;
					$scanURL = $baseURL.'/command'.'?apikey='.$radarrApiKey;
					$fetchMe = array();
					$fetchMe['name'] = 'MovieSearch';
					$fetchMe['movieId'] = $movieID;
					$curl = curl_init($scanURL);
					curl_setopt($curl, CURLOPT_HEADER, false);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt ($curl, CURLOPT_CAINFO, dirname(__FILE__) . "/cert/cacert.pem");
					curl_setopt($curl, CURLOPT_HTTPHEADER,
							array("Content-type: application/json"));
					curl_setopt($curl, CURLOPT_POST, true);
					curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fetchMe));
					curl_exec($curl);
				} else {
					$response['status'] = 'ERROR: No results.';
				}
			}
		}
        return $response;
	}


	function fetchList($serviceName) {
        $list = $selected = false;
		switch($serviceName) {
			case "sick":
				if ($_SESSION['sickList']) {
					$list = $_SESSION['sickList'];
				} else {
					testConnection("Sick");
					$list = $_SESSION['sickList'];
				}
				$selected = $_SESSION['sickProfile'];
				break;
			case "ombi":
				if ($_SESSION['ombiList']) {
					$list = $_SESSION['ombi'];
				} else {}
				break;
			case "sonarr":
				if ($_SESSION['sonarrList']) {
					$list = $_SESSION['sonarrList'];
				} else {
					testConnection("Sonarr");
					$list = $_SESSION['sonarrList'];
				}
				$selected = $_SESSION['sonarrProfile'];
				break;
			case "couch":
				if ($_SESSION['couchList']) {
					$list = $_SESSION['couchList'];
				}  else {
					testConnection("Couch");
					$list = $_SESSION['couchList'];
				}
				$selected = $_SESSION['couchProfile'];
				break;
			case "radarr":
				if ($_SESSION['radarrList']) {
					$list = $_SESSION['radarrList'];
				} else {
					testConnection("Radarr");
					$list = $_SESSION['radarrList'];
				}
				$selected = $_SESSION['radarrProfile'];
				break;
		}
		$html = "";
		if ($list) {
            foreach ($list as $id => $name) {
                $html .= "<option index='" . $id . "' id='" . $name . "' " . (($selected == $id) ? 'selected' : '') . ">" . $name . "</option>";
            }
        }
		return $html;
	}


	// Test the specified service for connectivity
	function testConnection($serviceName) {
		write_log("Function fired, testing connection for ".$serviceName);
		switch($serviceName) {

			case "Ombi":
				$ombiIP = $_SESSION['sickIP'];
				$ombiPort = $_SESSION['ombiPort'];
				$plexCred = $_SESSION['plexCred'];
				$authString = 'Authorization:Basic '.$plexCred;
				if (($ombiIP) && ($plexCred) && ($ombiPort)) {
					$url = $ombiIP . ":" . $ombiPort . "/api/v1/login";
                    write_log("Test URL is ".protectURL($url));
					$headers = array($authString, 'Content-Length: 0');
					$result = curlPost($url,false,false,$headers);
					write_log('Test result is '.$result);
					$result = ((strpos($result,'"success": true') ? 'Connection to CouchPotato Successful!': 'ERROR: Server not available.'));
				} else $result = "ERROR: Missing server parameters.";
				break;

			case "CouchPotato":
				$couchURL = $_SESSION['couchIP'];
				$couchApikey = $_SESSION['couchAuth'];
				$couchPort = $_SESSION['couchPort'];
				if (($couchURL) && ($couchApikey) && ($couchPort)) {
					$url = $couchURL . ":" . $couchPort . "/api/" . $couchApikey . "/profile.list";
					$result = curlGet($url);
					if ($result) {
						$resultJSON = json_decode($result,true);
						write_log("Hey, we've got some profiles: ".json_encode($resultJSON));
						$array = array();
						$first = false;
						foreach ($resultJSON['list'] as $profile) {
							$id = $profile['_id'];
							$name = $profile['label'];
							$array[$id] = $name;
							if (! $first) $first = $id;
						}
						$_SESSION['couchList'] = $array;
						if (! $_SESSION['couchProfile']) $_SESSION['couchProfile'] = $first;
						$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'], 'couchProfile',$first);
						$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'], 'couchList',$array);
						saveConfig($GLOBALS['config']);
					}
					$result = ((strpos($result,'"success": true') ? 'Connection to CouchPotato Successful!': 'ERROR: Server not available.'));
				} else $result = "ERROR: Missing server parameters.";
				break;

			case "Sonarr":
				$sonarrURL = $_SESSION['sonarrIP'];
				$sonarrApikey = $_SESSION['sonarrAuth'];
				$sonarrPort = $_SESSION['sonarrPort'];
				if (($sonarrURL) && ($sonarrApikey) && ($sonarrPort)) {
					$url = $sonarrURL . ":" . $sonarrPort . "/api/profile?apikey=".$sonarrApikey;
					$result = curlGet($url);
					if ($result) {
						write_log("Result retrieved.");
						$resultJSON = json_decode($result,true);
						write_log("Result JSON: ".json_encode($resultJSON));

						$array = array();
						$first = false;
						foreach($resultJSON as $profile) {
							$first = ($first ? $first : $profile['id']);
							$array[$profile['id']] = $profile['name'];
						}
						write_log("Final array is ".json_encode($array));
						$_SESSION['sonarrList'] = $array;
						if (! $_SESSION['sonarrProfile']) $_SESSION['sonarrProfile'] = $first;
						$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'], 'sonarrProfile',$first);
						$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'], 'sonarrList',$array);
						saveConfig($GLOBALS['config']);
					}
					$result = (($result !== false) ? 'Connection to Sonarr successful!' : 'ERROR: Server not available.');
				} else $result = "ERROR: Missing server parameters.";

				break;

			case "Radarr":
				$radarrURL = $_SESSION['radarrIP'];
				$radarrApikey = $_SESSION['radarrAuth'];
				$radarrPort = $_SESSION['radarrPort'];
				if (($radarrURL) && ($radarrApikey) && ($radarrPort)) {
					$url = $radarrURL . ":" . $radarrPort . "/api/profile?apikey=".$radarrApikey;
					write_log("Request URL: ".$url);
					$result = curlGet($url);
					if ($result) {
						write_log("Result retrieved.");
						$resultJSON = json_decode($result,true);
						$array = array();
						$first = false;
						foreach($resultJSON as $profile) {
							if ($profile === "Unauthorized") {
								return "ERROR: Incorrect API Token specified.";
							}
							$first = ($first ? $first : $profile['id']);
							$array[$profile['id']] = $profile['name'];
						}
						write_log("Final array is ".json_encode($array));
						$_SESSION['radarrList'] = $array;
						if (! $_SESSION['radarrProfile']) $_SESSION['radarrProfile'] = $first;
						$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'], 'radarrProfile',$first);

						$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'], 'radarrList',$array);
						saveConfig($GLOBALS['config']);
					}
					$result = (($result !== false) ? 'Connection to Radarr successful!' : 'ERROR: Server not available.');
				} else $result = "ERROR: Missing server parameters.";
				break;

			case "Sick":
				$sickURL = $_SESSION['sickIP'];
				$sickApiKey = $_SESSION['auth_sick'];
				$sickPort = $_SESSION['sickPort'];
				if (($sickURL) && ($sickApiKey) && ($sickPort)) {
					$sick = new SickRage($sickURL.':'.$sickPort, $sickApiKey);
					try {
						$result = $sick->sbGetDefaults();
					} catch (\Kryptonit3\SickRage\Exceptions\InvalidException $e) {
						write_log("Error Curling sickrage: ".$e);
						$result = "ERROR: ".$e;
						break;
					}
					$result = json_decode($result,true);
					write_log("Got some kind of result ".json_encode($result));
					$list = $result['data']['initial'];
					$array = array();
					$count = 0;
					$first = false;
					foreach ($list as $profile) {
						$first = ($first ? $first : $count);
						$array[$count] = $profile;
						$count++;
					}
					$_SESSION['sickList'] = $array;
					$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'], 'sickList',$array);
					saveConfig($GLOBALS['config']);
					write_log("List: ".print_r($_SESSION['sickList'],true));
					$result = (($result) ? 'Connection to Sick successful!' : 'ERROR: Server not available.');
				} else $result = "ERROR: Missing server parameters.";
				break;

			case "Plex":
				$url = $_SESSION['plexServerUri'].'?X-Plex-Token='.$_SESSION['plexServerToken'];
				write_log('URL is: '.protectURL($url));
				$result = curlGet($url);
				$result = (($result) ? 'Connection to '.$_SESSION['plexServerName'].' successful!': 'ERROR: '.$_SESSION['plexServerName'].' not available.');
				break;

			default:
				$result = "ERROR: Service name not recognized";
				break;
		}
		return $result;
	}


 // APIAI ITEMS
 // Put our calls to API.ai here
 // #######################################################################
 // Push API.ai bot to other's account.  This can go after Google approval

	// Returns a speech object to be read by Assistant
	function returnSpeech($speech, $contextName, $cards=false, $waitForResponse=false, $suggestions=false) {
		$suggestions = false; //TODO: Remove this whenever google gets me documentation
		write_log("Final Speech should be: ".$speech);
		if (! $cards) write_log("Card array is ".json_encode($cards));
		header('Content-Type: application/json');
		ob_start();
		$cardArray = $item = $items = $richResponse = $sugs = [];
		$output["speech"] = $speech;
		$returns = array();
		$contexts = array('waitforplayer','yes','promptfortitle');
		foreach($contexts as $context) {
			if ($context == $contextName) {
				$lifespan = 2;
			} else {
				$lifespan = 0;
			}
			$item = array('name'=>$context, 'lifespan'=>$lifespan);
			array_push($returns,$item);
		}
		$output["contextOut"] = $returns;
		$output["contextOut"][0]["name"] = $contextName;
		$output["contextOut"][0]["lifespan"] = 2;
		write_log("Expect response is ". $waitForResponse);
		$output["data"]["google"]["expect_user_response"] = $waitForResponse;
		$richResponse = array();
		$items = [];
		$simple = ['simple_response'=>['display_text'=>$speech,'text_to_speech'=>$speech]];
		array_push($items, $simple);

		if ($GLOBALS['screen']) {
            if (is_array($cards)) {
                write_log("Building card array.");
                $cardArray = [];
                array_push($cardArray, $item);
                if (count($cards) >= 2) {
                    //$output['data']['google']['system_intent']['intent'] = "actions.intent.OPTION";
                    $carousel = [];
                    foreach ($cards as $card) {
                        $item = [];
                        $item['image'] = $card['image'];
                        $item['image']['accessibilityText'] = $card['title'];
                        $item['title'] = $card['title'];
                        $item['description'] = $card['summary'];
                        $item['option_info']['key'] = 'play '.$card['title'];
                        array_push($carousel, $item);
                    }
                    $output['data']['google']['expectedInputs'][0]['possibleIntents'][0]['inputValueData']['listSelect']['items'] = $carousel;
                    //$output['data']['google']['system_intent']['spec']['option_value_spec']['list_select']['items'] = $carousel;
                    $output['data']['google']['expectedInputs'][0]['possibleIntents'][0]['inputValueData']['@type'] = "type.googleapis.com/google.actions.v2.OptionValueSpec";
                    $output['data']['google']['expectedInputs'][0]['possibleIntents'][0]['intent'] = "actions.intent.OPTION";
                } else {
	                $cards[0]['image']['accessibility_text']="Sweet picture you can't see.";
                    write_log("Should be formatting a BasicCard here: " . json_encode($cards[0]));
                    array_push($items,['basic_card'=>$cards[0]]);
                }
            }
        }
        $richResponse['items'] = $items;

        if ($GLOBALS['screen']) {
            if (is_array($suggestions)) {
                $sugs = [];
                foreach ($suggestions as $suggestion) {
                    array_push($sugs, ["title" => $suggestion]);
                }
                array_push($richResponse,['suggestions'=>$sugs]);
            }
        }
        $output['data']['google']['rich_response'] = $richResponse;
		//$output["data"] = $resultData;
		$output["displayText"] = $speech;
		$output["source"] = "api.php";
		ob_end_clean();
		echo json_encode($output);
		write_log("JSON out is ".json_encode($output));
	}

function returnSpeechv2($speech, $contextName, $cards=false, $waitForResponse=false, $suggestions=false) {
	$suggestions = false; //TODO: Remove this whenever google gets me documentation
	write_log("Final Speech should be: ".$speech);
	if (! $cards) write_log("Card array is ".json_encode($cards));
	header('Content-Type: application/json');
	ob_start();
	$cardArray = $items = $richResponse = $sugs = [];
	$output["speech"] = $speech;
	$output["contextOut"][0] = ["name"=>$contextName,"lifespan"=>2,"parameters"=>[]];
	$output["data"]["google"]["expect_user_response"] = boolval($waitForResponse);
	$output["data"]["google"]["isSsml"] = false;
	$output["data"]["google"]["noInputPrompts"] = [];
	$items[0] = ['simple_response'=>['text_to_speech'=>$speech,'display_text'=>$speech]];

	if (is_array($cards)) {
		write_log("Building card array.");
		if (count($cards) == 1) {
			write_log("Should be formatting a BasicCard here: " . json_encode($cards[0]));
			array_push($items, ['basic_card' => $cards[0]]);
		} else {
			$carousel = [];
			foreach ($cards as $card) {
				$item = [];
				$item['image'] = transcodeImage($card['image']);
				$item['image']['accessibilityText'] = $card['title'];
				$item['title'] = $card['title'];
				$item['description'] = $card['summary'];
				$item['option_info']['key'] = 'play '.$card['title'];
				array_push($carousel, $item);
			}
			$output['data']['google']['systemIntent']['intent'] = 'actions.intent.OPTION';
			$output['data']['google']['systemIntent']['data']['@type'] = 'type.googleapis.com/google.actions.v2.OptionValueSpec';
			$output['data']['google']['systemIntent']['data']['listSelect']['items'] = $carousel;
			//$output['data']['google']['system_intent']['spec']['option_value_spec']['list_select']['items'] = $carousel;
			$output['data']['google']['expectedInputs'][0]['possibleIntents'][0]['inputValueData']['@type'] = "type.googleapis.com/google.actions.v2.OptionValueSpec";
			$output['data']['google']['expectedInputs'][0]['possibleIntents'][0]['intent'] = "actions.intent.OPTION";

		}
	}

	$output['data']['google']['richResponse']['items'] = $items;

	if (is_array($suggestions)) {
		$sugs = [];
		foreach ($suggestions as $suggestion) {
			array_push($sugs, ["title" => $suggestion]);
		}
	}

	$output['data']['google']['richResponse']['suggestions'] = $sugs;

	ob_end_clean();
	echo json_encode($output);
	write_log("JSON out is ".json_encode($output));
}



	// Register our server with the mothership and link google account
	function registerServer() {
		$realIP = trim(curlGet('https://plex.tv/pms/:/ip'));
		$_SESSION['publicAddress'] = $GLOBALS['config']->get('user-_-'.$_SESSION['plexUserName'], 'publicAddress', $realIP);
		$registerUrl = "https://phlexserver.cookiehigh.us/api.php".
		"?apiToken=".$_SESSION['apiToken'].
		"&serverAddress=".htmlentities($_SESSION['publicAddress']);
		write_log("registerServer: URL is " . protectURL($registerUrl));
		$result = curlGet($registerUrl);
		if ($result == "OK") {
			$GLOBALS['config']->set('user-_-'.$_SESSION['plexUserName'],'lastCheckIn',time());
			saveConfig($GLOBALS['config']);
			write_log("Successfully registered with server.");
		} else {
			write_log("Server registration failed.");
		}
	}


	function checkSignIn() {
		if (isset($_POST['username']) && isset($_POST['password'])) {
			write_log("Function fired.");
			$userpass = base64_encode($_POST['username'] . ":" . $_POST['password']);
			$token = signIn($userpass);
			if ($token) {
				write_log("Token received.");
				$username = urlencode($token['username']);
				$userString = "user-_-" . $username;
				$authToken = $token['authToken'];
				$email = $token['email'];
				$avatar = $token['thumb'];
				$apiToken = checkSetApiToken($username);

				if (!$apiToken) {
					echo "Unable to set API Token, please check write access to Phlex root and try again.";
					write_log("Unable to set or retrieve API Token.","ERROR");
					die();
				} else {
					$_SESSION['apiToken'] = $apiToken;
					$_SESSION['plexUserName'] = $username;
					$_SESSION['plexToken'] = $authToken;
					// This is our user's first logon.  Let's make some files and an API key for them.
					$GLOBALS['config']->set($userString, "plexToken", $authToken);
					$GLOBALS['config']->set($userString, "plexEmail", $email);
					$GLOBALS['config']->set($userString, "plexAvatar", $avatar);
					$GLOBALS['config']->set($userString, "plexCred", $userpass);
					$GLOBALS['config']->set($userString, "plexUserName", $username);
					$GLOBALS['config']->set($userString, "apiToken", $apiToken);
					saveConfig($GLOBALS['config']);
				}
				write_log('Successfully logged in.');
			} else {
				echo 'ERROR';
				die();
			}

		}
	}
	
	function validateCredentials() {
		$user = false;
		$token = $_GET['apiToken'] ?? $_SERVER['HTTP_APITOKEN'] ?? $_SESSION['apiToken'] ?? false;
		// Check that we have some form of set credentials
		if ($token) {
			foreach ($GLOBALS['config'] as $section => $setting) {
				if ($section != "general") {
					if ((isset($setting['apiToken'])) && ($setting['apiToken'] == $token)) {
						$user = [];
						$user['apiToken'] = $setting['apiToken'];
						$user['plexUserName'] = $setting['plexUserName'];
						$user['plexCred'] = $setting['plexCred'];
						$user['plexToken'] = $setting['plexToken'];
						$user['valid'] = true;
						break;
					}
				}
			}
		}
		return $user;
	}


