<?php
////////////////////////////////////////////////////////////////////////////////
//            _____                        __     _____   __        __          
//           /  _  \   ______ ______ _____/  |_  /     \ |__| ____ |__|         
//          /  /_\  \ /  ___//  ___// __ \   __\/  \ /  \|  |/    \|  |         
//         /    |    \\___ \ \___ \\  ___/|  | /    Y    \  |   |  \  |         
//         \____|__  /____  >____  >\___  >__| \____|__  /__|___|  /__|         
//                 \/     \/     \/     \/             \/        \/             
// -----------------------------------------------------------------------------
//          Designed and Developed by Brad Jones <brad @="bjc.id.au" />         
// -----------------------------------------------------------------------------
////////////////////////////////////////////////////////////////////////////////

// Define our base directory
$base_dir = realpath(dirname(__FILE__));

// Include composer
$composer_loaded = false;
$dir = $base_dir;
do
{
	if(file_exists($dir.'/vendor/autoload.php'))
	{
		require($dir.'/vendor/autoload.php');
		$composer_loaded = true;
		break;
	}
}
while($dir = realpath("$dir/.."));

// Did we manage to include composer okay
if (!$composer_loaded)
{
	header("HTTP/1.1 500 Internal Server Error");
	echo 'Missing Composer: we cant find composer, please check your install';
	exit;
}

// Is our cache dir writeable
if (!is_writable($base_dir.'/cache'))
{
	header("HTTP/1.1 500 Internal Server Error");
	echo 'Invalid Query: cant write to cache dir';
	exit;
}

// Grab the query string
$query = urldecode($_SERVER['QUERY_STRING']);

/*
 * This is a slightly special case. When the view helpers are in debug mode
 * they will output the script or stylesheet tags for each individual file.
 * However with Less/Sass stylehsheets they need to be turned into CSS first.
 * Thus if we get a request for /less/FILE_NAME.less?stopcache=1234567890
 * We will compile the less on the fly, not cache it, and not minify it.
 * But note that the request must go to /less/, if it were to go to /css/
 * the file would exist and the web server would serve it as is.
 */
if (Gears\String\Contains($query, 'less'))
{
	// Extract the filename portion
	$special_css = $base_dir.'/css/'.Gears\String\Between($query, 'less/', '&');
	$special_css_type = 'Less';
}
elseif (Gears\String\Contains($query, 'sass'))
{
	// Extract the filename portion
	$special_css = $base_dir.'/css/'.Gears\String\Between($query, 'sass/', '&');
	$special_css_type = 'Sass';
}

if (isset($special_css))
{
	// Does the file actually exist
	if (file_exists($special_css))
	{
		// Grab the contents of the file
		$special_css_data = file_get_contents($special_css);

		// Grab the base dir of the file
		$special_css_base = pathinfo($special_css, PATHINFO_DIRNAME);

		// Compile the special css
		$compile = 'Gears\AssetMini\\'.$special_css_type.'Compile';
		$css = $compile($special_css_data, $special_css_base);
		
		// Output the compiled css
		header('Content-type: text/css;');
		echo $css['css'];
	}
	else
	{
		// It doesn't so error out
		header("HTTP/1.1 500 Internal Server Error");
		echo 'Asset Does Not Exist: '.$special_css;
	}

	// Stop here
	exit;
}

// Extract the asset name portion
$asset_name_string = Gears\String\Between($query, 'cache/', '.min');
$asset_name_array = explode('-', $asset_name_string);

// Attempt to parse it
if (count($asset_name_array) == 2)
{
	// This is what we will output
	$output = '';
	
	// What is the type of file we are generating?
	$type = pathinfo($query);
	$type = $type['extension'];
	
	// Get the time - this helps us invalidate the cache
	$time = $asset_name_array[1];
	
	// Create the hash name
	$hash_name = $asset_name_array[0];
	
	// Create some file names
	$group_hash = $base_dir.'/cache/'.$hash_name.'.json';
	$group_min = $base_dir.'/cache/'.$asset_name_string.'.min.'.$type;
	$group_gz = $group_min.'.gz';
	
	// What is the function name we will use to minify this asset
	$mini = 'Gears\AssetMini\\'.ucfirst($type).'Min';
	
	/*
	 * Check to see if the group file already exists
	 * I have noticed that from time to time nginx takes a few requests
	 * before it recongnises that the cached files exist, my guess at this
	 * point in file locking. ie: the php process still has the file pointer
	 * open. Anyway if the cached files don't exist lets create them.
	 */
	if (!file_exists($group_min))
	{
		// Clean up any old builds
		foreach(scandir($base_dir.'/cache') as $file)
		{
			if (strpos($file, $hash_name.'-') !== false)
			{
				unlink($base_dir.'/cache/'.$file);
			}
		}
		
		// This will contain a list of hashes for each file we minify.
		// We recreate the hash file now because some assets may import other
		// assets, in the case of less and sass.
		$hashes = [];

		// Read in the hash file
		$files = json_decode(file_get_contents($group_hash), true);
		$current_hash_time = array_pop($files);
		$current_group_hash = array_pop($files);
		
		// Loop through the files that make up this group
		foreach ($files as $file => $hash)
		{
			// Create the full asset file name
			$assetfilename = $base_dir.'/'.$file;

			// Does it exist
			if (file_exists($assetfilename))
			{
				// Read the file
				$data = file_get_contents($assetfilename);
				
				// Grab the hash
				$hashes[$file] = md5($data);

				// Is it a less file
				if (Gears\String\Contains($assetfilename, '.less'))
				{
					// Work out the basedir that the actual less file is in.
					$less_base = pathinfo($assetfilename);
					$less_base = $less_base['dirname'];
					
					// Compile the less first
					$less = Gears\AssetMini\LessCompile($data, $less_base);
					
					// Loop through the imported files and add them to our hashes
					foreach ($less['imported-files'] as $imported)
					{
						// Make sure the filepath is relative
						$relative = str_replace($base_dir.'/', '', $imported);
						$hashes[$relative] = md5(file_get_contents($imported));
					}
					
					// Minify it
					$output .= $mini($less['css'])."\n\n";
				}

				// Is it a sass file
				elseif (Gears\String\Contains($assetfilename, '.scss'))
				{
					// Work out the basedir that the actual sass file is in.
					$sass_base = pathinfo($assetfilename);
					$sass_base = $sass_base['dirname'];
					
					// Compile the less first
					$sass = Gears\AssetMini\SassCompile($data, $sass_base);
					
					// Loop through the imported files and add them to our hashes
					foreach ($sass['imported-files'] as $imported)
					{
						$relative = str_replace($base_dir.'/', '', $imported);
						$hashes[$relative] = md5(file_get_contents($imported));
					}
					
					// Minify it
					$output .= $mini($sass['css'])."\n\n";
				}

				// Is it already minified
				elseif (Gears\String\Contains($assetfilename, '.min.'))
				{
					// Simply concatenate it to our build.
					// We don't need to minify this, it's already been done.
					$output .= $data."\n\n";
				}
				else
				{
					// Minify it
					$output .= $mini($data)."\n\n";
				}
			}
			else
			{
				// It doesn't so error out
				header("HTTP/1.1 500 Internal Server Error");
				echo 'Asset Does Not Exist: '.$assetfilename;
				exit;
			}
		}
		
		// Compress the minfied data
		$output_gz = gzencode($output);
		
		// Cache the minfied version
		file_put_contents($group_min, $output);
		
		// Cache a gzipped version as well
		file_put_contents($group_gz, $output_gz);
		
		// Create a hash file so we can easily detect
		// when the cache is no longer valid
		$hashes[] = $current_group_hash; $hashes[] = $current_hash_time;
		file_put_contents($group_hash, json_encode($hashes));
	}
	else
	{
		// Just read in what we already have
		$output = file_get_contents($group_min);
		$output_gz = file_get_contents($group_gz);
	}
	
	// What content type is it?
	if ($type == 'css') header('Content-type: text/css;');
	if ($type == 'js') header('Content-type: text/javascript;');
	
	// Does the browser support gzip?
	if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false)
	{
		// We may as well return the gzipped data we just created.
		header('Vary: Accept-Encoding');
		header('Content-Encoding: gzip');
		$content = $output_gz;
	}
	else
	{
		$content = $output;
	}
	
	// How long is the content
	header('Content-Length: '.strlen($content));
	
	// Output the minfied asset
	echo $content;
}
else
{
	header("HTTP/1.1 500 Internal Server Error");
	echo 'Invalid Query: asset name';
}