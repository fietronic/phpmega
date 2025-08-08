<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>📷 PHP Media Gallery Compatibility Check</h1>";
echo "<pre>";

$required_extensions = [
    'json',
    'fileinfo',
    'mbstring',
    'posix',
];

$required_functions = [
    'shell_exec',
    'finfo_open',
    'finfo_file',
    'json_encode',
    'json_decode',
    'mb_strtolower',
    'file_get_contents',
    'file_put_contents',
    'scandir',
    'mkdir',
    'realpath',
    'is_writable',
    'is_dir',
    'chmod',
];

$directories = [
    'fotos',
    '_fotocache',
];

// --- EXTENSIONS ---
echo "\n🧩 Checking Required PHP Extensions:\n";
foreach ($required_extensions as $ext) {
    echo extension_loaded($ext)
        ? "✔ Extension '$ext' is loaded.\n"
        : "❌ Extension '$ext' is MISSING.\n";
}

// --- FUNCTIONS ---
echo "\n🔧 Checking Required PHP Functions:\n";
$disabled = explode(',', ini_get('disable_functions'));
$disabled = array_map('trim', $disabled);
foreach ($required_functions as $func) {
    if (!function_exists($func)) {
        echo "❌ Function '$func' is not available.\n";
    } elseif (in_array($func, $disabled)) {
        echo "❌ Function '$func' is DISABLED in php.ini.\n";
    } else {
        echo "✔ Function '$func' is enabled.\n";
    }
}

// --- DIRECTORIES ---
echo "\n📂 Checking Directory Setup:\n";
foreach ($directories as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        echo "⚠️  Directory '$dir' does not exist. Trying to create... ";
        if (mkdir($path, 0755, true)) {
            echo "✔ Created.\n";
        } else {
            echo "❌ FAILED to create '$dir'.\n";
        }
    } else {
        echo "✔ Directory '$dir' exists.\n";
    }

    if (is_writable($path)) {
        echo "✔ '$dir' is writable.\n";
    } else {
        echo "❌ '$dir' is NOT writable.\n";
    }
}

// --- FFMPEG ---
echo "\n🎞️  Checking FFmpeg Availability:\n";
$ffmpeg_check = shell_exec('ffmpeg -version 2>&1');
if ($ffmpeg_check && stripos($ffmpeg_check, 'ffmpeg version') !== false) {
    echo "✔ FFmpeg is available.\n";
} else {
    echo "❌ FFmpeg is not available or shell_exec() failed.\n";
}

// --- DISPLAY RESULT ---
echo "\n✅ DONE. Fix ❌ issues before running the gallery.\n";
echo "</pre>";
