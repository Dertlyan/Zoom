<?php
/*
	Zoom 13.06.03 by yurik@unix.am
*/
if(!class_exists('YZoom')) {
class YZoom {
 private $options = array();
 
 public function __construct($options=null) {
	$this->basePath = $_SERVER['DOCUMENT_ROOT'].'/';
	$this->waterContent = $_SERVER['SERVER_NAME'];
	$this->waterAlign = 'C';
	$this->waterMargins = array('top'=>0, 'right'=>0, 'bottom'=>0, 'left'=>0);
	$this->cacheUrl = 'assets/cache/images/';
	$this->capUrl = 'assets/images/0.png';
	$this->font = 'Scada-Bold';
	$this->patterns = array(
		'img' => '/<img\s+[^>]*>/i',
		'atr' =>'/\s+(src|align|alt|width|height|class)\s*=\s*"([^"]+)"/i'
	);
	// Apply external options
	if($options) $this->options = array_merge($this->options, $options);
	$this->cachePath = $this->basePath . $this->cacheUrl;
	$this->modes = array('zoom','resize','skip');
	$this->mode = 'zoom';
	// Creating cap if not exists
	if($this->capUrl) {
		$capPath = $this->basePath . $this->capUrl;
		if(!file_exists($capPath))
			$this->createTextImage($capPath,300,300,'Изображение готовится');
	}
 }
 
 public function __set($key, $value) {
	$this->options[$key] = $value;
 }
 
 public function __get($key) {
	return array_key_exists($key, $this->options) ? $this->options[$key] : null; 
 }
 
 public function process(&$content) {
	$tags = array();
	// Find all Img tags or return if not found
	if(!$imgCount = preg_match_all($this->patterns['img'], $content, $tags)) return 0;

	// Found images loop
	for($i=0; $i<$imgCount; $i++) {
		// Default replacing tag
		$tags[1][$i] = $tags[0][$i];
		
		// Checking mode
		$mode = $this->mode;
		foreach($this->modes as $m) {
			if(strpos($tags[0][$i],'{'.$m.'}') !== false) {
				$tags[1][$i] = str_replace('{'.$m.'}', '', $tags[0][$i]);
				$mode = $m;
				break;
			}
		}

		// Reading image attributes
		$img = array();
		if($attrCount = preg_match_all($this->patterns['atr'], $tags[0][$i], $imgAttrs)) {
			for($n=0; $n<$attrCount; $n++) $img[$imgAttrs[1][$n]] = $imgAttrs[2][$n];
			// Cleaning start slash
			$img['src'] = ltrim($img['src'],'/');
		} else continue;
		// Ckip if not set both Width and Height
		if(!isset($img['width']) && !isset($img['height'])) continue;
		
		// Emppty image url check
		if(empty($img['src'])) {
			$img['src'] = $this->capUrl;
			$tags[1][$i] = $this->setAttribute($tags[1][$i], 'src', $this->capUrl);
		}
		$img['file'] = array();
		// Image file existence check		
		$img['file']['path'] = $this->basePath . $img['src'];
		if(!file_exists($img['file']['path']) || is_dir($img['file']['path'])) continue;

		// Get real image size
		$size = GetImageSize($img['file']['path']);
		$img['file']['width'] = $size[0];
		$img['file']['height'] = $size[1];
		unset($size);
		// Calculate proportional Width or Height if not set
		if(!$img['height']) $img['height'] = round($img['width'] * $img['file']['height'] / $img['file']['width']);
		if(!$img['width']) $img['width'] = round($img['height'] * $img['file']['width'] / $img['file']['height']);
		
		$img['cache'] = array();
		// Generating image cache Url and Path
		$img['cache']['url'] = $this->getCachedImageUrl($img['src'], $img['width'].'x'.$img['height']);
		$img['cache']['path'] = $this->basePath . $img['cache']['url'];
		$this->makePath($img['cache']['path']); // create directories
		
		// Cached image exists? Or is not fresh
		if(!file_exists($img['cache']['path']) || (filemtime($img['cache']['path']) < filemtime($img['file']['path']))) {
			$this->createThumb($img['file']['path'], $img['cache']['path'], $img['file']['width'], $img['file']['height'], $img['width'], $img['height']);
		}

		// Generation new tag
		if($mode != 'skip') $tags[1][$i] = str_replace($img['src'], $img['cache']['url'], $tags[1][$i]);
		// Adding link to source image
		if($mode == 'zoom') $tags[1][$i] = '<a href="'.$img['src'].'"'. (isset($img['alt'])?' title="'.$img['alt'].'"':null) .'>'.$tags[1][$i].'</a>';
	}
	
	// Updating output
	$content = str_replace($tags[0], $tags[1], $content);
 }
 
 // Generate cached image url
 private function getCachedImageUrl($sourceUrl, $prefix=null) {
	// Normalizing path slashes
	$sourceUrl = str_replace("\\", "/", $sourceUrl);
	$cacheUrl = preg_replace("/(assets\/images\/)(.*)/i", $this->cacheUrl.'$2', $sourceUrl);
	$path = pathinfo($cacheUrl);
	return $path['dirname'] .'/'. $path['filename'] . ($prefix?'_'.$prefix:null) . ($path['extension'] ? '.'.$path['extension']:null);
 }
 
 // Make full path with subdirs
 private function makePath($path) {
	// If path not exist create it
	$_path = pathinfo($path);
	if(!is_dir($_path['dirname'])) {
		mkdir($_path['dirname'], 0777, true);
	}
 }
 
 // Set attribute of element
 private function setAttribute($element, $name, $value) {
	// Attribute exists?
	$out = preg_replace('/('.$name.'\s*=\s*")([^"]*)(")/i','\\1'.$value.'\\3',$element, -1, $count);
	if($count==0 && $value) {
		$out = preg_replace('/(<\w+)([^>]+>)/i','\\1 '.$name.'="'.$value.'"\\2',$element);
	}
	return $out;
 }

 // Remove attribute from element
 private function removeAttribute($element, $name) {
	return preg_replace('/\s+'.$name.'\s*=\s*"[^"]*"/i','',$element);
 }
 
 private function createThumb($source, $destination, $sw, $sh, $dw, $dh, $quality=85, $filters=null) {
	if(!file_exists($source)) return false;
	// Increase memory limit to support larger files
	// ini_set('memory_limit', '128M');
	
	// Proportional cropping
	$x = $y = 0;
	if($sw/$dw < $sh/$dh) {
	$y = -($sw/$dw*$dh-$sh)/2;
	$sh = $sw/$dw*$dh;
	}
	else {
		$x = -($sh/$dh*$dw-$sw)/2;
		$sw = $sh/$dh*$dw;
	}
	
	// Proportional resizing
	$kw = $kh = 0;
	if($dw/$dh < $sw/$sh) $kh = $dh - intval($dh/($sw/$sh))/($dh/$dw);
	elseif($dw/$dh > $sw/$sh) $kw = $dw - (intval($dw*($sw/$sh))/($dw/$dh));
	
	$src = $this->image($source);
	$dst = imagecreatetruecolor($dw, $dh);
	imagefill($dst, 0, 0, imagecolorallocate($dst,255,255,255));
	//imagealphablending($dst, false);
	//imagesavealpha($dst, true);
	imagecopyresampled($dst, $src, 0+intval($kw/2), 0+intval($kh/2), $x, $y, $dw-$kw, $dh-$kh, $sw, $sh);
	// Save thumbnail
	$this->image($source, $dst, $destination, $quality);

	imagedestroy($src);
	imagedestroy($dst);
	return true;
 }
 
 public function safeOutput($source) {
	$source = str_replace("\\","/",$source);
	// If image not exists redirect to cap
	if(!file_exists($source)) $this->redirect();
	// Exclude cap image watermarking
	if($this->basePath . $this->capUrl != $source) {
		$cachedPath = $this->getCachedImageUrl($source,'safe');
		// Safe image exists? Is it more fresh that source file?
		if(file_exists($cachedPath) && (filemtime($cachedPath) > filemtime($source)))
			$image = $this->image($cachedPath);
		else {
			$image = $this->image($source);
			$this->watermark($image, $this->waterContent);
			// Save safe image to cache
			$this->image($source, $image, $cachedPath);
		}
	} else $image = $this->image($source);
	// Output safe image
	$this->image($source, $image);
 }
 
 private function image($filename, $source=null, $destination=null, $quality=85) {
	$type = $this->getFileType($filename);
	// Set server headers by image type
	if(is_resource($source) && !$destination) header('Content-type: image/'.$type);
	switch($type) {
		case 'jpeg':
			if($source) return imagejpeg($source, $destination, $quality);
			$image = imagecreatefromjpeg($filename);
			break;
		case 'gif':
			if($source) return imagegif($source, $destination);
			$image = imagecreatefromgif($filename);
			break;
		case 'png':
			if($source) return imagepng($source, $destination);
			$image = imagecreatefrompng($filename);
			break;
	}
	return $image;
 }
 
 private function getFileType($filename) {
	$extension = pathinfo(mb_strtolower($filename, 'UTF-8'), PATHINFO_EXTENSION);
	return $extension =='jpg' ? 'jpeg' : $extension;
 }
 
 private function getFontPath($font=null) {
	$font = $font ? $font : $this->font;
	return str_replace("\\", "/", dirname(__FILE__)).'/fonts/'. $font . '.ttf';
 }
 
 private function watermark(&$image, $content, $alpha=90) {
	if(file_exists($content) && preg_match("/\.(jp?g|gif|png)$/i", $content)) {
		return $this->imageWatermark($image, $content, $this->waterAlign);
	} else {
		return $this->textWatermark($image,$content,$alpha);
	}
 }
 
 private function getWaterPosition($srcWidth, $srcHeight, $dstWidth, $dstHeight, $align=null, $margins=null) {
	$align = $align ? $align : $this->waterAlign;
	$margins = $margins ? $margins : $this->waterMargins;
	$x = $margins['left'];
	$y = $margins['top'];
	$a = array(
		'XC' => round($srcWidth/2 - $dstWidth/2) - $margins['left'],
		'YC' => round($srcHeight/2 - $dstHeight/2) - $margins['top'],
		'XR' => abs($srcWidth - $dstWidth - $margins['right']),
		'YB' => abs($srcHeight - $dstHeight - $margins['bottom'])
	);
	switch($align) {
		case 'C':
			$x = $a['XC'];
			$y = $a['YC'];
		case 'T':
			$x = $a['XC'];
			break;
		case 'TR':
			$x = $a['XR'];
			break;
		case 'R':
			$x = $a['XR'];
			$y = $a['YC'];
			break;
		case 'B':
			$x = $a['XC'];
			$y = $a['YB'];
			break;
		case 'BL':
			$y = $a['YB'];
			break;
		case 'BR':
			$x = $a['XR'];
			$y = $a['YB'];
			break;
		case 'L':
			$y = $a['YC'];
			break;
	}
	return array($x,$y);
 }
 
 private function imageWatermark($img, $waterPath, $align='C') {
	$width = imagesx($img); $height = imagesy($img);
	$watermark = $this->image($waterPath);
	$w = imagesx($watermark); $h = imagesy($watermark);
	list($x,$y) = $this->getWaterPosition($width, $height, $w, $h, $align);
	imagecopy($img, $watermark, $x, $y, 0, 0, $w, $h);
	imagedestroy($watermark);
 }
 
 private function textWatermark($img, $text, $alpha=90, $r=255, $g=255, $b=255, $font=null) {
	if(!mb_strlen($text,'UTF-8')) return;
	$font = $this->getFontPath($font);
	$width = imagesx($img);
	$height = imagesy($img);
	$angle = 0; //-rad2deg(atan2((-$height),($width))); // diagonal
	$c = imagecolorallocatealpha($img, $r, $g, $b, $alpha); // alpha 0..127
	$cb = imagecolorallocatealpha($img, 255-$r, 255-$g, 255-$b, 120);
	$size = ((($width+$height)/2)*2/strlen($text))*0.8;
	$box  = imagettfbbox($size, $angle, $font, $text);
	$x = $width/2 - abs($box[4] - $box[0])/2;
	$y = $height/2 + abs($box[5] - $box[1])/2;
	imagettftext($img,$size ,$angle, $x, $y, $c, $font, $text);
 }
 
 private function createTextImage($filename, $width, $height, $text, $font=null) {
	$font = $this->getFontPath($font);
	$img = imagecreatetruecolor($width, $height);
	imagefill($img, 0, 0, $this->color('eeeeee'));
	$r=50; $g=50; $b=50; $alpha=100; $angle=0;
	$c = imagecolorallocatealpha($img, $r, $g, $b, $alpha);
	$size = (($width+$height)/2)*2/strlen($text);
	$box  = imagettfbbox($size, $angle, $font, $text);
	$x = $width/2 - abs($box[4] - $box[0])/2;
	$y = $height/2 + abs($box[5] - $box[1])/2;
	imagettftext($img, $size ,$angle, $x, $y, $c, $font, $text);
	imagepng($img, $filename);
	imagedestroy($img);
 }
 
 private function color($color) {
	if(is_array($color)) {
	}
	elseif(is_numeric($color)) {
	}
	else {
	 $r = hexdec(substr($color, 0, 2));
	 $g = hexdec(substr($color, 2, 2));
	 $b = hexdec(substr($color,-2));
	 return $b + $g*pow(2,8) + $r*pow(2,16);
	}
 }
 
 public function redirect($url=null) {
	$url = $url ? $url : $this->capUrl;
	header('HTTP/1.1 301 Moved Permanently');
	header('Location: /' . $url);
	exit();
 }

}
}
?>