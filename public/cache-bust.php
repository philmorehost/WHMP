<?php
// OPcache invalidator - access this once then delete
$file = __DIR__ . '/resources/views/client-auth/dashboard.php';
if (function_exists('opcache_invalidate')) {
    $result = opcache_invalidate($file, true);
    echo $result ? "OPcache invalidated for dashboard.php" : "Failed or OPcache not active";
} else {
    echo "OPcache not available";
}
echo " | File mtime: " . date('Y-m-d H:i:s', filemtime($file));
echo " | Size: " . filesize($file) . " bytes";