<?php
// PHP Media Gallery - Single File Implementation
// Configuration
$config = [
    'source' => __DIR__ . '/fotos',
    'cache' => __DIR__ . '/_fotocache',
    'image_ext' => ['jpg','jpeg','png','gif','webp'],
    'video_ext' => ['mp4','webm','mov','avi'],
    'thumb_width' => 400,
    'thumb_height' => 300,
    'ffmpeg' => 'ffmpeg',
    'ffprobe' => 'ffprobe',
    'preview_count' => 4,
    'cache_ttl' => 14*24*3600,
];

// Determine base URL dynamically
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$baseUrl = $protocol . '://' . $host . $scriptDir;
$baseUrl = rtrim($baseUrl, '/'); // Remove trailing slash

$warnings = [];
$config['source'] = realpath($config['source']);

/*
if($config['source']===false){
    $warnings[] = 'Source directory not found';
    $config['source'] = __DIR__;
}
$config['cache_enabled'] = is_dir($config['cache']) && is_writable($config['cache']);
if(!$config['cache_enabled']){
    $warnings[] = 'Cache directory missing or unwritable - thumbnails will not persist';
}*/

// Source directory: try to create if missing
if (!is_dir($config['source'])) {
    if (mkdir($config['source'], 0755, true)) {
        $config['source'] = realpath($config['source']);
    } else {
        $warnings[] = 'Source directory missing and could not be created; using script folder';
        $config['source'] = __DIR__;
    }
} else {
    $config['source'] = realpath($config['source']);
}


// Cache directory: try to create if missing
if (!is_dir($config['cache'])) {
    if (mkdir($config['cache'], 0755, true)) {
        $config['cache_enabled'] = is_writable($config['cache']);
    } else {
        $config['cache_enabled'] = false;
        $warnings[] = 'Failed to create cache directory; thumbnails will not persist';
    }
} else {
    $config['cache_enabled'] = is_writable($config['cache']);
    if (!$config['cache_enabled']) {
        $warnings[] = 'Cache directory not writable; thumbnails will not persist';
    }
}

$ffmpeg_ok = false;
$ffmpeg_check = @shell_exec($config['ffmpeg'].' -version 2>&1');
if($ffmpeg_check){
    $ffmpeg_ok = true;
}else{
    $warnings[] = 'FFmpeg not found';
}

// sanitize dir parameter
$dirParam = isset($_GET['dir']) ? $_GET['dir'] : '';
$dirParam = str_replace('\\','/',$dirParam);
$dirParam = trim($dirParam,'/');
$dirParam = preg_replace('#\.\.+#','',$dirParam); // remove traversal
$relPath = $dirParam;
$currentPath = realpath($config['source'].'/'.$relPath);
if($currentPath===false || strpos($currentPath,$config['source'])!==0){
    $currentPath = $config['source'];
    $relPath = '';
}

// bulk download
if (isset($_GET['download'])) {
    $baseName = basename($currentPath ?: $config['source']);
    $timestamp = date('Ymd_His');
    $zipName = "{$baseName}_{$timestamp}";

    $tmpZip = tempnam(sys_get_temp_dir(), 'zip');

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        die("Failed to create ZIP archive");
    }

    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($currentPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($rii as $file) {
        if ($file->isFile()) {
            $realPath = $file->getPathname();
            $relativePath = substr($realPath, strlen($config['source']) + 1);
            $zip->addFile($realPath, $relativePath);
            $zip->setCompressionName($relativePath, ZipArchive::CM_STORE);
        }
    }

    $zip->close();

    // Clean output buffers to prevent corruption
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($zipName) . '.zip"');
    header('Content-Length: ' . filesize($tmpZip));
    readfile($tmpZip);
    unlink($tmpZip);
    exit;
}




function finfoAllowed($file){
    static $fi = null; if($fi===null) $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi,$file);
    return $mime;
}

function collectDir($dir,$config){
    $cacheFile = $config['cache'].'/listing_'.md5($dir).'.json';
    $dirMtime = filemtime($dir);
    if($config['cache_enabled'] && file_exists($cacheFile)){
        $data = json_decode(file_get_contents($cacheFile),true);
        if($data && $data['mtime'] >= $dirMtime){
            return $data;
        }
    }
    $res = ['mtime'=>$dirMtime,'dirs'=>[],'files'=>[]];
    $items = scandir($dir);
    foreach($items as $item){
        if($item==='.'||$item==='..') continue;
        $full = $dir.'/'.$item;
        if(is_dir($full)){
            if(!hasContent($full,$config)) continue; // skip empty
            $res['dirs'][] = ['name'=>$item,'path'=>$full];
        }else{
            $ext = strtolower(pathinfo($item,PATHINFO_EXTENSION));
            if(!in_array($ext,$config['image_ext']) && !in_array($ext,$config['video_ext'])) continue;
            $mime = finfoAllowed($full);
            if(strpos($mime,'image/')!==0 && strpos($mime,'video/')!==0) continue;
            $res['files'][] = ['name'=>$item,'path'=>$full,'ext'=>$ext,'mime'=>$mime,'size'=>filesize($full)];
        }
    }
    usort($res['dirs'], fn($a,$b)=>strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));
    usort($res['files'], fn($a,$b)=>strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));
    if($config['cache_enabled']) file_put_contents($cacheFile,json_encode($res));
    return $res;
}

function hasContent($dir,$config){
    $stats = subdirStats($dir,$config);
    return $stats['count']>0 || $stats['subdirs'];
}

function subdirStats($dir,$config){
    $cacheFile = $config['cache'].'/stats_'.md5($dir).'.json';
    $dirMtime = filemtime($dir);
    if($config['cache_enabled'] && file_exists($cacheFile)){
        $data = json_decode(file_get_contents($cacheFile),true);
        if($data && $data['mtime'] >= $dirMtime){
            return $data;
        }
    }
    $count = 0; $preview=[]; $subdirs = false;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
    foreach($rii as $file){
        if($file->isDir()){$subdirs=true; continue;}
        $ext = strtolower($file->getExtension());
        if(!in_array($ext,$config['image_ext']) && !in_array($ext,$config['video_ext'])) continue;
        $mime = finfoAllowed($file->getPathname());
        if(strpos($mime,'image/')!==0 && strpos($mime,'video/')!==0) continue;
        $count++;
        if(count($preview)<$config['preview_count']) $preview[] = $file->getPathname();
    }
    $data = ['mtime'=>$dirMtime,'count'=>$count,'preview'=>$preview,'subdirs'=>$subdirs];
    if($config['cache_enabled']) file_put_contents($cacheFile,json_encode($data));
    return $data;
}

function thumb($file,$config,$ffmpeg_ok,&$warnings,$baseUrl){
    $hash = md5($file);
    $dest = $config['cache'].'/'.$hash.'.jpg';
    $srcTime = filemtime($file);
    $needs = true;
    if($config['cache_enabled'] && file_exists($dest)){
        $age = time() - filemtime($dest);
        if(filemtime($dest)>= $srcTime && $age < $config['cache_ttl']) $needs=false;
    }
    if($needs){
        if(!$ffmpeg_ok) return false;
        $scale = 'scale='.$config['thumb_width'].':'.$config['thumb_height'].':force_original_aspect_ratio=decrease';
        $src = escapeshellarg($file);
        $out = escapeshellarg($dest);
        $ext = strtolower(pathinfo($file,PATHINFO_EXTENSION));
        if(in_array($ext,$config['video_ext'])){
            $probe = shell_exec($config['ffmpeg'].' -i '.$src.' 2>&1');
            $sec = 0;
            if(preg_match('/Duration: (\d+):(\d+):(\d+\.?\d*)/',$probe,$m)){
                $sec = $m[1]*3600 + $m[2]*60 + $m[3];
            }
            $ss = $sec>0 ? $sec/2 : 1;
            $cmd = $config['ffmpeg']." -y -ss $ss -i $src -vframes 1 -vf $scale -qscale:v 3 $out 2>&1";
        }else{
            $cmd = $config['ffmpeg']." -y -i $src -vf $scale -qscale:v 3 $out 2>&1";
        }
        $ret = shell_exec($cmd);
        if(!file_exists($dest)){
            $warnings[] = 'Thumbnail generation failed for '.htmlspecialchars(basename($file));
            return false;
        }
    }
    if($config['cache_enabled']){
        return $baseUrl . '/_fotocache/' . basename($dest);
    }else{
        $data = file_get_contents($dest);
        unlink($dest);
        return 'data:image/jpeg;base64,'.base64_encode($data);
    }
}

// Function to convert file path to absolute URL
function getFileUrl($filePath, $config, $baseUrl) {
    // Get relative path from source directory
    $relativePath = str_replace($config['source'], '', $filePath);
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    
    // Split path and encode each part separately to avoid double-encoding
    $pathParts = explode('/', $relativePath);
    $encodedParts = array_map('rawurlencode', $pathParts);
    $encodedPath = implode('/', $encodedParts);
    
    // Construct absolute URL
    return $baseUrl . '/fotos/' . $encodedPath;
}

$listing = collectDir($currentPath,$config);

// breadcrumbs
$crumbs = [];
$crumbs[] = '<a href="?">fotos</a>';
$parts = $relPath === '' ? [] : explode('/',$relPath);
$accum = '';
foreach($parts as $part){
    if($part==='') continue;
    $accum .= ($accum?'/' :'').$part;
    $crumbs[] = '<a href="?dir='.rawurlencode($accum).'">'.htmlspecialchars($part).'</a>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<title>PHPMega Gallery</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox@3.2.0/dist/css/glightbox.min.css"/>
<style>
body{background:#111;color:#eee;font-family:Arial,sans-serif;margin:0;padding:0}
header{padding:1rem;font-size:1.2rem}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;padding:1rem}
.grid a{display: inline-block; width:<?=$config['thumb_width'];?>;height:<?=$config['thumb_height'];?>;color:#eee;text-decoration:none;position:relative; }
.grid img{width:100%; height: 100%; object-fit:cover;display:block;border-radius:4px; outline: 1px solid rgba(250,250,250,0.5);}
.folder{background:#222;border-radius:4px;padding:1rem;text-align:center;position:relative}
.folder .icon{font-size:120px;opacity:0.2}
.folder .name{position:absolute;bottom:0.5rem;left:0;right:0;font-weight:bold}
.folder .badge{position:absolute;top:0.5rem;right:0.5rem;background:#444;padding:2px 6px;border-radius:12px;font-size:0.8rem}
.folder .previews{position:absolute;top:10px;left:10px;display:flex;gap:2px}
.folder .previews img{width:40px;height:40px;object-fit:cover;border-radius:2px}
.placeholder{width:100%;height:200px;display:flex;align-items:center;justify-content:center;font-size:64px;background:#333;border-radius:4px}
.breadcrumb{padding:0 1rem 1rem}
footer{padding:1rem;font-size:0.8rem;color:#bbb}
#filmstrip{position:fixed;bottom:0;left:0;right:0;background:rgba(0,0,0,0.9);padding:0.5rem;display:flex;overflow-x:auto;gap:0.5rem;z-index:999999!important}
#filmstrip img{height:60px;width:auto;cursor:pointer;opacity:0.5}
#filmstrip img.active{opacity:1;border:2px solid #fff}
</style>
</head>
<body>
<header><?php echo implode(' / ',$crumbs); ?> | <a href="?dir=<?php echo rawurlencode($relPath); ?>&download=1">Download ZIP</a></header>
<div class="grid">
<?php
if($relPath!==''){
    $parent = dirname($relPath);
    if($parent==='.') $parent='';
    echo '<a href="?dir='.rawurlencode($parent).'" class="folder"><div class="icon">..</div></a>';
}
foreach($listing['dirs'] as $dir){
    $stats = subdirStats($dir['path'],$config);
    $link = '?dir='.rawurlencode(trim($relPath.'/'.$dir['name'],'/'));
    echo '<a href="'.$link.'" class="folder">';
    echo '<div class="icon">📁</div>';
    echo '<div class="badge">'.$stats['count'].'</div>';
    echo '<div class="previews">';
    foreach($stats['preview'] as $p){
        $th = thumb($p,$config,$ffmpeg_ok,$warnings,$baseUrl);
        if($th) echo '<img src="'.$th.'" alt="" loading="lazy"/>';
    }
    echo '</div>';
    echo '<div class="name">'.htmlspecialchars($dir['name']).'</div>';
    echo '</a>';
}
foreach($listing['files'] as $file){
    $fileAbsoluteUrl = getFileUrl($file['path'], $config, $baseUrl);
    $thumb = thumb($file['path'],$config,$ffmpeg_ok,$warnings,$baseUrl);
    $isVideo = in_array($file['ext'],$config['video_ext']);
    if($thumb){
        #echo '<a href="'.$fileAbsoluteUrl.'" class="glightbox" data-gallery="main" data-type="'.($isVideo?'video':'image').'" data-title="'.htmlspecialchars($file['name']).'" data-desc="'.number_format($file['size']/1024,1).' KB">';
        echo '<a href="'.$fileAbsoluteUrl.'" class="glightbox" data-gallery="main" data-type="'.($isVideo?'video':'image').'" >';
        echo '<img src="'.$thumb.'" loading="lazy" alt="."/>&nbsp;';
        echo '</a>';
    }else{
        echo '<div class="placeholder">'.($isVideo?'📷':'🖼️').'</div>';
    }
}
if(empty($listing['dirs']) && empty($listing['files'])) echo '<p style="padding:1rem">Files Not Found</p>';
?>
</div>
<footer>
<?php foreach($warnings as $w) echo '<div>'.htmlspecialchars($w).'</div>'; ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/glightbox@3.2.0/dist/js/glightbox.min.js"></script>
<script>
const lightbox = GLightbox({selector: '.glightbox', loop: false,touchNavigation: true});
const items = Array.from(document.querySelectorAll('.glightbox'));
lightbox.on('open', function(){
    const strip = document.createElement('div');
    strip.id = 'filmstrip';
    document.body.appendChild(strip);
    items.forEach((el,i)=>{
        const img = document.createElement('img');
        img.src = el.querySelector('img').src;
        img.dataset.index = i;
        img.addEventListener('click',()=> lightbox.goToSlide(i));
        strip.appendChild(img);
    });
    lightbox.on('slide_changed', ({current})=>{
        document.querySelectorAll('#filmstrip img').forEach(im=>im.classList.remove('active'));
        const act = document.querySelector(`#filmstrip img[data-index="${current.index}"]`);
        if(act){act.classList.add('active');act.scrollIntoView({behavior:'smooth',inline:'center'});}        
    });
});
lightbox.on('close', ()=>{const s=document.getElementById('filmstrip');if(s) s.remove();});
</script>
</body>
</html>
