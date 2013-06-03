<?php
/*
	Zoom image preprocessor
*/
$dir = dirname(__FILE__);
$filename = $dir .'/'. $_GET['q'];
require_once($_SERVER['DOCUMENT_ROOT'].'/assets/plugins/zoom/zoom.class.php');
$zoom = new YZoom();
// Watermark image detection
/*
$watermark = $dir .'/'. 'watermark.png';
if(file_exists($watermark)) {
	$zoom->waterContent = $watermark;
	$zoom->waterAlign = 'BR';
	$zoom->waterMargins = array('top'=>0, 'right'=>20, 'bottom'=>20, 'left'=>0);
}
*/
$zoom->safeOutput($filename);
unset($zoom);
?>