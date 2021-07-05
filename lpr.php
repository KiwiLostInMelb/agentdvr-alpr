<?php
//Shorten
define('DS',DIRECTORY_SEPARATOR);

/**
 Modified from https://raw.githubusercontent.com/stefanvangastel/openalpr-php-rest-api/master/check.php
 Plate API information: https://docs.platerecognizer.com/?python#recognition-api

	Note: only required fields for plate recognition are returned - no ability for determining plate license state or model/color/etc from alpr. 
	candidates is not required for agentdvr so is not populated.
*/

/*
Example response from platerecognizer
{
  "processing_time": 65.224,
  "results": [
    {
      "box": {
        "xmin": 404,
        "ymin": 499,
        "xmax": 511,
        "ymax": 528
      },
      "plate": "xxxxxx",
      "region": {
        "code": "au-nsw",
        "score": 0.213
      },
      "score": 0.906,
      "candidates": [
        {
          "score": 0.906,
          "plate": "xxxxx"
        },
        {
          "score": 0.904,
          "plate": "xxxxx"
        }
      ],
      "dscore": 0.784,
      "vehicle": {
        "score": 0.866,
        "type": "Sedan",
        "box": {
          "xmin": 210,
          "ymin": 207,
          "xmax": 689,
          "ymax": 599
        }
      }
    }
  ],
  "filename": "0603_qO6pT_image.jpg",
  "version": 1,
  "camera_id": null,
  "timestamp": "2021-07-02T06:03:46.297193Z"
}
*/

/*
	php and php-fpm setup
	/etc/php-fpm.d/www.conf controls the PATH and the LD_LIBRARY_PATH
	if alpr is not working then add the correct paths.  I use Apache on Fedora.  It may be different for NGIX and other distributions?

	for Apache (httpd) on Fedora this was:
		env[PATH] = /usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/usr/lib64/ccache
		env[LD_LIBRARY_PATH] = /usr/local/lib:/usr/local/lib64 
	
	Also required to be set in /etc/php.ini for obvious reasons is:
		post_max_size = 8M
		enable_post_data_reading = On
		file_uploads = On
		upload_max_filesize = 8M

	The pfp-fpm error log for Apache on Fedora is at /var/log/php-fpm/www-errorlog.log

	Remember there is no authentication on anything here so dont expose your web server to the internet.  I use an unmapped local port.
*/

// Check tmp dir and write permissions
if( ! file_exists('tmp') ){
	if( ! mkdir('tmp') ){
		//Try to create
		error_log("Error: Cannot create tmp dir in current dir, please check webserver permissions or create this manually with owner apache:apche",0);
		$response['error']= 'Error: Cannot create tmp dir in current dir.';
		respond($response);
	}	
}

$inipath = php_ini_loaded_file();
if (!($inipath)) {
	error_log("Error: Php.ini file is not loaded.",0);	
	$response['error']= 'Php.ini file is not loaded';
	responderror($response);
}

/**
 * Check exec and ALPR command
 */
if( ! function_exists("exec")){
	error_log("Error: php exec not available, safe mode?",0);	
	$response['error']= 'Error: php exec not available, safe mode?';
	responderror($response);
}


if( empty(run('/usr/local/bin/alpr --version')) ){
	$mypath = run('echo $PATH');
	error_log("Error: alpr command not found, is it installed and in your PATH? Is LD_CONFIG set? PATH:".$mypath,0);
	$response['error']= 'Error: alpr command not found, is it installed and in your PATH? PATH:'.$mypath;
	responderror($response);
}

/**
 * Check POSTED data.
 */
if( empty($_FILES['upload']) ){
	error_log("Error: No image data recieved. Please send a base64 encoded image",0);
	error_log($_REQUEST);
	error_log($_POST);
	error_log($_FILES);
	$response['error']= 'Error: No image data recieved. Please send a base64 encoded image' ;
	responderror($response);
}

/**
 * Save image to disk (tmp)
 */

$filename = 'tmp'.DS.uniqid(rand(), true) . '.jpg';

if (! move_uploaded_file($_FILES['upload']['tmp_name'], $filename)) {
	error_log("Error: Failed saving image to disk, please check webserver permissions. From:".$_FILES['upload']['tmp_name']." ,To:".$filename,0);
	error_log($_FILES);
	$response['error']= 'Error: Failed saving image to disk, please check webserver permissions.';
	responderror($response);
}


/**
 * Run ALPR command on image
 */

// We are using auwide - change this for your region

$result1 = run('alpr -n 1 --country auwide --json '.$filename);


// Check result.
if( empty( $result1[sizeof($result1)-1] )) {
	error_log("Error: ALPR returned no result.",0);
}

// Use the last line of the alpr result just in case alpr is running in debug symbol mode
$response1 = json_decode( $result1[sizeof($result1)-1], TRUE);

if( empty( $response1['results'][0]['coordinates'][0] )) {
	// log error but dont return yet - we will create an empty results section below instead.
	error_log("Error: ALPR returned no coordinates result.".$result1[sizeof($result1)-1],0);
}

$img = imagecreatefromjpeg($filename);
$boxcolor = imagecolorallocate($img, 255, 255, 255); // for a white rectangle
$textcolor = imagecolorallocate($img, 255, 255, 255); // for white text


// There is probably a better way to manipulate JSON than this but I am using a raw string
// start JSON
$json_string = '{';

// Processing time
$json_string .= '"processing_time" : '.$response1['processing_time_ms'];

// Add results header
$json_string .= ', "results": [ '; 

$resultsIndex = 0; // first result

while (! empty( $response1['results'][$resultsIndex]['coordinates'][0] )) {

	// Add each item
	$json_string .= '{'; 
	
	$x1 = $response1['results'][$resultsIndex]['coordinates'][0]['x'];
	$y1 = $response1['results'][$resultsIndex]['coordinates'][0]['y'];
	$x2 = $response1['results'][$resultsIndex]['coordinates'][2]['x'];
	$y2 = $response1['results'][$resultsIndex]['coordinates'][2]['y'];


	// Add BOX - agentdvr uses this to draw on its image
	$json_string .= ' "box": { ';
	$json_string .= ' "xmin": '.$x1;
	$json_string .= ', "ymin":'.$y1;
	$json_string .= ', "xmax":'.$x2; 
	$json_string .= ', "ymax":'.$y2;
	$json_string .= '}';


	// add plate info
	$plate = $response1['results'][$resultsIndex]['plate'];
	$json_string .= ', "plate": "'.$plate.'"';

	// add score
	$score = ($response1['results'][$resultsIndex]['confidence']/100);
	$json_string .= ', "score": '.$score;
	// item end	
	$json_string .= '}';

	// draw box and lpr text on image in temp directory
	// not needed if you dont want a separate record of the plates or if debugging is not required
	//imagesetthickness($img,2); // bigger box - optional
	imagerectangle($img,$x1,$y1,$x2,$y2, $boxcolor);
	// Enter text - plate found and confidence in %
	imagestring($img, 4, $x1,($y1 - 15), $plate." ".(floor($response1['results'][$resultsIndex]['confidence'] *100)/100)."%", $textcolor);

	// Increment index
	$resultsIndex = $resultsIndex + 1;

	// check for more results
	if (! empty( $response1['results'][$resultsIndex]['coordinates'][0] )){
		// add separator for next plate block as we have more results from alpr
		$json_string .= ',';
	}
}

// save modified image
imagejpeg($img,$filename);
// Free up memory
imagecolordeallocate($img, $boxcolor);
imagecolordeallocate($img, $textcolor);
imagedestroy($img);



// results end
$json_string .= ']'; 

// Extra bits
$json_string .= ', "filename": "'.$filename.'"'; // filename in web temp directory - not used by agentdvr
$json_string .= ', "version": 1'; // not used but hey we could return alpr version
$json_string .= ', "camera_id": null'; // maybe this could be used for something?
$json_string .= ', "timestamp": "'.date(DATE_RFC2822).'"'; // not used by agentdvr but may be useful
 
// END json string
$json_string .= '}';


// Remove image from tmp dir - optional if you dont want to debug the image later - maybe another web page for viewing is required?
// unlink($filename);

//Respond with results
respond($json_string);


/**
 * Aux functions
 */

//Sets headers and responds json
function respond($response){
	header('Access-Control-Allow-Origin: *');
	header('Cache-Control: no-cache, must-revalidate');
	header('Content-type: application/json');
	// error_log("LPR Responding:".$response);  // debug write to log if needed
	echo $response;
	exit;
}

function responderror($response){
	header('Access-Control-Allow-Origin: *');
	header('Cache-Control: no-cache, must-revalidate');
	header('Content-type: application/json', true, 201);
	//http_response_code(201);  // not sure how this will be handled - I think agent dvr re-requests if not status 200
	echo json_encode($response);
	error_log("LPR Responding with error:".$response);
	exit;
}

//Runs local command and returns output
function run($command){
	$output = array();
	exec($command,$output);
	return $output;
}



?>
