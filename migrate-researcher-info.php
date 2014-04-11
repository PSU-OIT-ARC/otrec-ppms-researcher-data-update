<?php

if (!file_exists('settings.php')) {
    echo "*** ERROR - Please create database settings file \"settings.php\" from the template.\n";
    die(1);
}
require('settings.php');

$verbose = false;
$photopath = 'photos';
$continue = false;

// Command line settings
for ($i = 1; $i < count($argv); $i++) {
    switch($argv[$i]) {
        case '-y':
            $continue = true;
            break;
        case '-v':
            $verbose = true;
            break;
        case '-d':
            if (!isset($argv[$i+1]) || empty($argv[$i+1])) {
                die("*** ERROR: Invalid file path argument\n");
            }
            $photopath = $argv[++$i];
            break;
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

if (!$continue) {
    echo "Migrate OTREC PPMS Researcher data? (y/n): ";
    $handle = fopen("php://stdin",'r');
    $input = trim(fgets($handle));
    if ($input != 'y') {
        exit();
    }
}

die($photopath);
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
CD.field_id_59 AS photo
FROM exp_channel_titles AS CT
JOIN exp_channel_data AS CD ON CT.entry_id = CD.entry_id
WHERE CT.channel_id = 6
EOQ;

$result = $db_source->query($researcher_query);
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    
    // echo "{$row['firstname']} {$row['lastname']}: {$row['email']}\n";
    updateResearcher($row,$db_target);
}



echo " * Process completed successfully\n";


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
    -d [directory_path] : Set file path for migrated files
    
==========================================================

EOH;

echo $help;
}

function updateResearcher($row, $db) {
    $stmt = $db->prepare('SELECT * FROM user WHERE email = ?');
    $stmt->execute(array($row['email']));
    $researcher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // if (!$researcher) {
        // Should we try and lookup by lastname?
    // }
    
    if ($researcher) {
        // save image here if it exists and can be downloaded
        
        echo $verbose ? "*'".$row['email'] ."',\n" : '';
        
        // Update researcher title (and photo if set)
        $stmt = $db->prepare('UPDATE user SET title = ? WHERE user_id = ?');
        $stmt->execute(array($row['job_title'],$researcher['user_id']));
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