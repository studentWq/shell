<?php
/*
Author: Sohay
Title: Optimized Backdoor Scanner v1.0.1
*/

// Styling for output
echo '<style>body {background-color:#000;color:green;} body,td,th { font: 9pt Courier New;margin:0;vertical-align:top; } span,h1,a { color:#00ff00} span { font-weight: bolder; } h1 { border:1px solid #00ff00;padding: 2px 5px;font: 14pt Courier New;margin:0px; } div.content { padding: 5px;margin-left:5px;} a { text-decoration:none; } a:hover { background:#ff0000; } .ml1 { border:1px solid #444;padding:5px;margin:0;overflow: auto; } .bigarea { width:100%;height:250px; } input, textarea, select { margin:0;color:#00ff00;background-color:#000;border:1px solid #00ff00; font: 9pt Monospace,"Courier New"; } form { margin:0px; } #toolsTbl { text-align:center; } .toolsInp { width: 80%; } .main th {text-align:left;} .main tr:hover{background-color:#5e5e5e;} .main td, th{vertical-align:middle;} pre {font-family:Courier,Monospace;} </style>';

// Prevent timeout and allocate sufficient memory
ini_set('max_execution_time', 300); // 5 minutes
ini_set('memory_limit', '512M');
set_time_limit(0);
error_reporting(0);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
ob_implicit_flush(1);

$path = getcwd();
if (isset($_GET['dir'])) {
    $path = $_GET['dir'];
}

if (isset($_GET['kill'])) {
    unlink(__FILE__);
    die("<font color='yellow'>Script deleted successfully.</font>");
}

echo "<a href='?kill'><font color='yellow'>[Self Delete]</font></a><br>";
echo '<form action="" method="get"> <input type="text" name="dir" value="' . htmlspecialchars($path) . '" style="width: 548px;"> <input type="submit" value="Scan"></form><br>';
echo "CURRENT DIR: <font color='yellow'>" . htmlspecialchars($path) . "</font><br>";

if (isset($_GET['delete'])) {
    unlink($_GET['delete']);
    $status = !file_exists($_GET['delete']) ? "<font color='yellow'>Success</font>" : "<font color='red'>FAILED</font>";
    echo "TRY TO DELETE: " . htmlspecialchars($_GET['delete']) . " $status <br>";
    exit;
}

scanBackdoor($path);

// Function to scan backdoors in files
function scanBackdoor($current_dir, $depth = 0, $max_depth = 5) {
    if ($depth > $max_depth) return; // Prevent scanning too deeply

    if (is_readable($current_dir)) {
        $dir_location = scandir($current_dir);
        foreach ($dir_location as $file) {
            if ($file === "." || $file === "..") {
                continue;
            }
            $file_location = str_replace("//", "/", $current_dir . '/' . $file);
            $file_ext = pathinfo($file_location, PATHINFO_EXTENSION);

            if ($file_ext === "php") {
                checkBackdoor($file_location);
            } elseif (is_dir($file_location)) {
                scanBackdoor($file_location, $depth + 1, $max_depth);
            }
        }
    }
}

// Function to check for suspicious patterns in files
function checkBackdoor($file_location) {
    global $path;
    $pattern = "#(exec|gzinflate|file_put_contents|file_get_contents|system|passthru|shell_exec|move_uploaded_file|eval|base64)#";

    if (is_readable($file_location)) {
        $contents = file_get_contents($file_location);
        if (strlen($contents) > 0 && preg_match($pattern, strtolower($contents))) {
            echo "[+] Suspect file -> <a href='?delete=" . urlencode($file_location) . "&dir=" . urlencode($path) . "'><font color='yellow'>[DELETE]</font></a> <font color='red'>" . htmlspecialchars($file_location) . "</font> <br>";
            saveLog("shell-found.txt", $file_location);
            echo '<textarea name="content" cols="80" rows="15">' . htmlspecialchars($contents) . '</textarea><br><br>';
        }
    }
}

// Function to save log of suspicious files
function saveLog($filename, $content) {
    $file = fopen($filename, "a");
    fwrite($file, $content . "\n");
    fclose($file);
}
