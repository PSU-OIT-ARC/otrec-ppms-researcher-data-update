<?php
/**
 *  @filename migrate-researcher-info.php
 *  @author Matt Anglin
 *  
 *  This script is intended to be run in the CLI.
 *  Please set the database settings for both the source (ExpressionEngine)
 *  and target (Django PPMS) databases. Make sure you create the target
 *  directory and that it is writeable. Also make sure to set the URL prefix
 *  option (especially if you are using absolute server paths!) if it differs from
 *  the server path.
 */
 
// Main
if (!file_exists('settings.php')) {
    echo "*** ERROR - Please create database settings file \"settings.php\" from the template.\n";
    die(1);
}
require('settings.php');

$continue = $verbose = false;
$httppath = $photopath = 'photos';

// Command line settings
for ($i = 1; $i < count($argv); $i++) {
    switch($argv[$i]) {
        // Force execution
        case '-y':
            $continue = true;
            break;
        // Enable verbose execution
        case '-v':
            $verbose = true;
            break;
        // Server directory to save photo file to
        case '-d':
            if (!isset($argv[$i+1]) || empty($argv[$i+1])) {
                die("*** ERROR: Invalid file path argument\n");
            }
            $httppath = $photopath = $argv[++$i];
            break;
        // URL photo path prefix to set in database
        case '-p':
            if (!isset($argv[$i+1]) || empty($argv[$i+1])) {
                die("*** ERROR: Invalid file path argument\n");
            }
            $httppath = $argv[++$i];
            break;
        // Help menu
        case '?':
        case 'h':
        case '-h':
        case 'help':
            help();
            exit();
            break;
        default:
            die("Invalid option \"{$argv[$i]}\"\n");
    }
}

// Check file path
if (!is_writable($photopath)) {
    if (!file_exists($photopath)) {
        die("*** ERROR: Target directory does not exist {$photopath}\n");
    } else {
        die("*** ERROR: Cannot write to directory {$photopath}. Please check your permissions.\n");
    }
}

// Confirm migration from cli
if (!$continue) {
    echo "Migrate OTREC PPMS Researcher data? (y/n): ";
    $handle = fopen("php://stdin",'r');
    $input = trim(fgets($handle));
    if ($input != 'y') {
        exit();
    }
}

// Create DB Connections
echo $verbose ? " * Connecting to source database...\n" : '';
$db_source = new PDO($database['source']['driver'] . ':host=' . $database['source']['host'] . ';dbname=' . $database['source']['database'] . ';charset=utf8',$database['source']['user'], $database['source']['password']);
if (!$db_source) {
    die("*** Unable to connect to source database\n");
}

echo $verbose ? " * Connecting to target database...\n" : '';
$db_target = new PDO($database['target']['driver'] . ':host=' . $database['target']['host'] . ';dbname=' . $database['target']['database'] . ';charset=utf8',$database['target']['user'], $database['target']['password']);
if (!$db_target) {
    die("*** Unable to connect to target database\n");
}

// Get source information from ExpressionEngine Database
echo $verbose ? " * Retrieving source table data...\n" : '';
$file_prefs_query = 'SELECT id, url, server_path FROM exp_upload_prefs';
$result = $db_source->query($file_prefs_query);
$file_dirs = array();
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    $file_dirs[$row['id']] = $row['url'];
}

$researcher_query = <<<EOQ
SELECT
CD.field_id_22 AS firstname,
CT.title AS lastname,
CD.field_id_35 AS email,
CD.field_id_25 AS job_title,
CD.field_id_36 AS degree_1,
CD.field_id_37 AS degree_2,
CD.field_id_38 AS degree_3,
CD.field_id_39 AS degree_4,
CD.field_id_40 AS website,
CD.field_id_41 AS profile,
CD.field_id_59 AS photo
FROM exp_channel_titles AS CT
JOIN exp_channel_data AS CD ON CT.entry_id = CD.entry_id
WHERE CT.channel_id = 6
EOQ;

$result = $db_source->query($researcher_query);
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    updateResearcher($row,$db_target);
}

echo $verbose ? " * Process completed successfully\n" : '';

/**
 *  Prints help information to console
 */
function help() {
    $help = <<<EOH
==========================================================
    This script will migrate OTREC PPMS researcher information from
    an ExpressionEngine database into the new Python Django PPMS
    database. Please ensure that you have set the database information
    in settings.php and that your database schema are correct.
    
    Options:
 ----------------------------------------------------------------------------
 
    ? : Display this help information
    -v : Execute verbose
    -y : Execute without confirmation
    -d [directory_path] : Set file path to save migrated files to (omit trailing slash)
    -p [url_path_prefix] : Set url prefix where the files can be located via http (omit trailing slash)
    
    NOTE: If you omit the URL prefix [-d] option, the url prefix value will default to the directory path.
    
==========================================================

EOH;

    echo $help;
}

/**
 *  Looks up a researcher in the target PPMS DB by
 *  email address updates title, sets education, and
 *  downloads and sets photo if available
 */
function updateResearcher($row, $db) {
    global $httppath, $verbose;
    
    $stmt = $db->prepare('SELECT * FROM user WHERE email = ?');
    $stmt->execute(array($row['email']));
    $researcher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // if (!$researcher) {
        // Should we try and lookup by lastname?
    // }
    
    if ($researcher) {
        echo $verbose ? "*'".$row['email'] ."',\n" : '';
        
        // Update researcher title (and photo if set)
        $sql = 'UPDATE user SET title = ?, profile = ?, website = ? WHERE user_id = ?';
        $values = array();
        
        // save image here if it exists and can be downloaded
        $photo = downloadFile($row['photo']);
        if ($photo) {
            $sql = 'UPDATE user SET photo = ?, title = ?, profile = ?, website = ? WHERE user_id = ?';
            $values[] = $httppath . '/' . $photo;
        }

        $values[] = $row['job_title'];
        $values[] = $row['profile'];
        $values[] = $row['website'];
        $values[] = $researcher['user_id'];
        
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo $verbose ? "** SAVED USER TITLE: {$row['job_title']}\n" : '';
        
        // Update researcher education
        for ($i = 1; $i < 5; $i++) {
            if (!empty($row['degree_'.$i])) {
                $stmt = $db->prepare('INSERT INTO user_education (education,user_id) VALUES (?, ?)');
                $result = $stmt->execute(array($row['degree_'.$i],$researcher['user_id']));
                echo $verbose ? "*** SAVED EDUCATION: {$row['degree_'.$i]}\n" : '';
            }
        }
    }
}

/**
 *  Attempts to retrieve a file from a given url according
 *  to it's directory path replacement from the ExpressionEngine
 *  file preferences. Saves file to the set photopath and returns
 *  the filename.
 */
function downloadFile($file) {
    global $file_dirs, $photopath,$verbose;
    if (!empty($file)) {
        $dir_index = preg_replace('/{filedir_([0-9]+)}.+/','$1',$file);
        $filename = preg_replace('/{filedir_[0-9]+}(.+)/','$1',$file);
        $url = isset($file_dirs[$dir_index]) ? $file_dirs[$dir_index] : false;
        
        if ($url) {
            if ($file_data = file_get_contents($url.$filename)) {
                file_put_contents($photopath . '/' . $filename,$file_data);
                
                echo $verbose ? "Saved file {$photopath}/{$filename}\n" : '';
                
                return $filename;
            }
        } else {
            echo $verbose ? "WARNING: Could not retrieve file {$url}{$filename}\n" : '';
        }
    }
}
