<?php defined('SYNCSYSTEM') || die('No direct script access.');
class SYNC {

	public static $CONFIG = array();
	private static $FILES = array();
	private static $TOTALSIZE = 0;



	public static function init_ignores() {
		GLOBAL $IGNORES;
		$IGNORES = '';
		if(isset(self::$CONFIG['IGNORE_FILE_LIST'])) {
			$IGNORES = implode('|', self::$CONFIG['IGNORE_FILE_LIST']);
			$IGNORES = addcslashes($IGNORES, '.');
			$IGNORES = strtr($IGNORES, array('?' => '.?', '*' => '.*'));
			$IGNORES = '/^('.$IGNORES.')$/i';
		}
	}



	public static function print_script($script) {
		return '<script type="text/javascript">'.$script.'</script>';
	}



	private static function MD5_Checksum($realdir) {
		$dir = g2u(str_replace(LOCAL_DIR, '', $realdir));
		return '<input type="hidden" name="file['.$dir.']" value="'.md5_file($realdir).'" />'."\r\n";
	}



	private static function MD5_Verify($realdir) {
		$dir = g2u(str_replace(LOCAL_DIR, '', $realdir));
		if($_POST['file'][$dir] != '') {
			if(md5_file($realdir) != $_POST['file'][$dir]) {
				unset($_POST['file'][$dir]);
				return ("parent.addUnmatchItem('$dir', false);");
			}
			unset($_POST['file'][$dir]);
		} else {
			return ("parent.addUnmatchItem('$dir', 'local');");
		}
	}



	private static function push_list($realdir) {
		array_push(self::$FILES, "$realdir");
		self::$TOTALSIZE += filesize("$realdir");
	}



	private static function listfiles($dir = ".", $callback = null) {
		GLOBAL $sublevel, $fp, $IGNORES;
		$return  = '';
		$dir     = preg_replace('/^\.\//i', '', $dir); //删除“当前文件夹”起始指示符
		$realdir = LOCAL_DIR.$dir;
		if(is_file("$realdir")) {
			return (method_exists('SYNC', $callback) ? call_user_func_array(array('SYNC', $callback), array($realdir)) : null);
		}
		if($handle = @opendir("$realdir")) {
			$sublevel++;
			while($file = readdir($handle)) {
				if($file == '.' || $file == '..' || preg_match($IGNORES, $file)) continue;
				$return .= self::listfiles("$dir/$file", $callback);
			}
			closedir($handle);
			$sublevel--;
		}
		return $return;
	}



	public static function push() {
		self::catchthepackage();
		exit();
	}



	private static function catchthepackage() {
		global $_FILES;
		if($_FILES["file"]["error"] > 0) {
			echo "Return Code: ".$_FILES["file"]["error"]."<br />";
		} else {
			move_uploaded_file($_FILES["file"]["tmp_name"], "./".$_FILES["file"]["name"]);
			//echo '<br />';
			//echo "Upload: ".$_FILES["file"]["name"]."<br />";
			//echo "Type: ".$_FILES["file"]["type"]."<br />";
			//echo "Size: ".($_FILES["file"]["size"] / 1024)." Kb<br />";
			//echo "Temp file: ".$_FILES["file"]["tmp_name"]."<br />";
			//echo "Stored in: "."./".$_FILES["file"]["name"].'<br />';
			self::dounzip();
			return $_FILES;
		}
	}



	public static function after_MD5_Compare_on_local($targetList) {
		$filenum    = 0;
		$sublevel   = 0;
		$hiddenform = $javascriptHTML;
		foreach($targetList as $file) {
			$hiddenform .= self::listfiles($file, 'MD5_Verify');
		}
		if(count($_POST['file'])) {
			foreach($_POST['file'] as $file => $md5) {
				$hiddenform .= ("parent.addUnmatchItem('$file', 'remote');");
			}
		}
		$hiddenform .= 'parent.output();';
		exit(self::print_script($hiddenform));
	}



	public static function after_upload_on_local($targetList) {
		echo '<script>var html = "";';
		plusHTML($_POST['displayInfo']);
		$operation = strtr($_REQUEST['do'], array('after' => 'continue'));
		echo 'parent.document.getElementById("displayRect").innerHTML += html;</script>';
		if($_POST['continue'] == 'continue')
			echo Page_Template::form('http://localhost/Sync/index.php?do=pulltolocal', '<input type="hidden" name="do" value="$operation" />')
				.Page_Template::autoSubmit();
	}



	public static function after_dnload_on_local($targetList) {
		self::cache_list($targetList);
		self::$FILES = explode("\n", file_get_contents('Sync.txt'));
		self::packfiles();
		echo Page_Template::form('http://localhost/Sync/index.php?do=pulltolocal').Page_Template::autoSubmit();
	}



	public static function continue_upload_on_local($targetList) {
		return self::put();
	}



	/**
	 * the flag function make the upload operation
	 */
	public static function upload($targetList) {
		GLOBAL $SessionSite;
		self::cache_list($targetList);
		return self::put();
	}



	public static function dnload($targetList) {
		return null;
	}



	/**
	 * caching the files which will be operation
	 */
	private static function cache_list($targetList) {
		self::$FILES = array();
		foreach($targetList as $file) {
			self::listfiles($file, 'push_list');
		}
		$list = implode("\n", self::$FILES);
		file_put_contents('Sync.txt', $list);
	}



	/**
	 * pack the files and upload to the site
	 * it split the big list to mini size to make
	 * and pass to prevent timeout
	 */
	private static function put() {
		GLOBAL $SessionSite, $continue;
		$CACHEFILES        = explode("\n", file_get_contents('Sync.txt'));
		self::$TOTALSIZE   = 0;
		self::$FILES       = array();
		$UPLOAD_LIMIT_SIZE = $_REQUEST['UPLOAD_LIMIT_SIZE'];
		$UPLOAD_LIMIT_SIZE = min($UPLOAD_LIMIT_SIZE, 20 * 1024 * 1024);
		$UPLOAD_LIMIT_SIZE = max($UPLOAD_LIMIT_SIZE, 0);
		do {
			self::push_list(array_shift($CACHEFILES));
		} while(count($CACHEFILES) && self::$TOTALSIZE + filesize($CACHEFILES[0]) < $UPLOAD_LIMIT_SIZE);
		$continue = count($CACHEFILES) ? 'continue' : 'end';
		file_put_contents('Sync.txt', implode("\n", $CACHEFILES));
		$res     = self::packfiles();
		$package = realpath('package.zip');
		$data    = array('file' => "@$package");
		return $res.self::curlrequest("http://$SessionSite/sync/?do=push", $data);
	}



	private static function curlrequest($url, $data, $method = 'post') {
		$ch = curl_init(); //初始化CURL句柄
		curl_setopt($ch, CURLOPT_URL, $url); //设置请求的URL
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //设为TRUE把curl_exec()结果转化为字串，而不是直接输出
		//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method); //设置请求方式
		//curl_setopt($ch, CURLOPT_HTTPHEADER, array("X-HTTP-Method-Override: $method")); //设置HTTP头信息
		curl_setopt($ch, CURLOPT_POST, 1); //以post方式提交数据
		$level = error_reporting(0);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data); //设置提交的字符串
		error_reporting($level);
		$time = ini_get('max_execution_time');
		ini_set('max_execution_time', '80');
		$document = curl_exec($ch); //执行预定义的CURL
		ini_set('max_execution_time', $time);
		if(!curl_errno($ch)) {
			$info = curl_getinfo($ch);
			$time = '<div>Took '.$info['total_time'].' seconds to send a request to '.$info['url'].'</div>';
		} else {
			echo 'Curl error: '.curl_error($ch);
		}
		curl_close($ch);
		return $time.$document;
	}



	public static function pulltolocal() {
		global $SessionSite;
		$reuslt    = "";
		$reuslt    = file_get_contents("http://$SessionSite/package.zip");
		$fp        = file_put_contents('./package.zip', $reuslt);
		$path      = './';
		$name      = 'package.zip';
		$remove    = 0;
		$unzippath = './';
		if(file_exists('./package.zip') && is_file('./package.zip')) {
			$Zip    = new PclZip('./package.zip');
			$result = $Zip->extract(PCLZIP_OPT_PATH, LOCAL_DIR);
			if($result) {
				$statusCode = 200;
				$list       = $Zip->listContent();
				$fold       = 0;
				$fil        = 0;
				$tot_comp   = 0;
				$tot_uncomp = 0;
				foreach($list as $key => $val) {
					if($val['folder'] == '1') {
						++$fold;
					} else {
						++$fil;
						$tot_comp += $val['compressed_size'];
						$tot_uncomp += $val['size'];
					}
				}
				$message = '<font color="green">解压文件详情：</font><font color="red">共'.$fold.' 个目录，'.$fil.' 个文件</font><br />';
				$message .= '<font color="green">压缩文档大小：</font><font color="red">'.($tot_comp).'</font><br />';
				$message .= '<font color="green">解压文档大小：</font><font color="red">'.($tot_uncomp).'</font><br />';
				//$message .= '<font color="green">解压总计耗时：</font><font color="red">' . G('_run_start', '_run_end', 6) . ' 秒</font><br />';
			} else {
				$statusCode = 300;
				$message .= '<font color="blue">解压失败：</font><font color="red">'.$Zip->errorInfo(true).'</font><br />';
				//$message .= '<font color="green">执行耗时：</font><font color="red">' . G('_run_start', '_run_end', 6) . ' 秒</font><br />';
			}
		}
		echo($message);
	}



	private static function packfiles() {
		$Zip = new PclZip('package.zip');
		$Zip->create(self::$FILES, PCLZIP_OPT_REMOVE_PATH, LOCAL_DIR);
		self::info($Zip);
	}



	public static function MD5_Compare($targetList) {
		//$fp = fopen('./md5.xml', 'w');
		//fwrite($fp, '');
		//fclose($fp);
		//$fp = fopen('./md5.xml', 'a');
		$hiddenform = '';
		$filenum    = 0;
		$sublevel   = 0;
		foreach($targetList as $file) {
			$hiddenform .= self::listfiles($file, 'MD5_Checksum');
		}
		//$includefiles = serialize($includefiles);
		//$package->createfile();
		//fclose($fp);
		return $hiddenform;
	}



	public static function _sync() { //这个居然是构造函数？？？只能在前面加下划线
		$upload = $dnload = $delete = array();
		foreach($_POST['file'] as $file => $option) {
			switch($option) {
				case 'ignore':
					$ignorelist = file_get_contents($localdir.'./sync/ignorelist.txt');
					if(!in_array($file, explode("\n", $ignorelist))) {
						$fp = fopen($localdir.'./sync/ignorelist.txt', 'a');
						fwrite($fp, "\n$file");
						fclose($fp);
					}
					break;
				case 'upload':
					$upload[] = $file;
					break;
				case 'dnload':
					$dnload[] = $file;
					//echo '<input type="hidden" name="dnload[]" value="' . $file . '" />';
					break;
				case 'delete':
					//@unlink(u2g($localdir.$file));
					$delete[] = $file;
					//echo '<input type="hidden" name="delete[]" value="' . $file . '" />';
					break;
			}
			$op = $option.'[]';
			$hiddenform .= "<input type='hidden' name='$op' value='$file' />\n";
		}
		$hiddenform .= "<input type='hidden' name='operation' value='md5checkedsync' />";
		if(count($upload)) {
			packfiles($upload);
			$package = realpath('package.zip');
			$data    = array('file' => "@$package");
			$res     = curlrequest("http://$SessionSite/sync/?operation=push", $data);
			echo($res);
		}
	}



	private function dounzip() {
		$path      = './';
		$name      = 'package.zip';
		$remove    = 0;
		$unzippath = './';
		if(file_exists($path.$name) && is_file($path.$name)) {
			$Zip    = new PclZip($path.$name);
			$result = $Zip->extract($path.(('./' == $unzippath || '。/' == @$_POST['unzippath']) ? '' : $unzippath), $remove);
			if($result) {
				$statusCode = 200;
				self::info($Zip);
				//$message .= '<font color="green">解压总计耗时：</font><font color="red">' . G('_run_start', '_run_end', 6) . ' 秒</font><br />';
			} else {
				$statusCode = 300;
				$message .= '<font color="blue">解压失败：</font><font color="red">'.$Zip->errorInfo(true).'</font><br />';
				echo $message;
				//$message .= '<font color="green">执行耗时：</font><font color="red">' . G('_run_start', '_run_end', 6) . ' 秒</font><br />';
			}
		}
	}



	private static function info($Zip) {
		$list = $Zip->listContent();
		if($list) {
			$fold       = 0;
			$fil        = 0;
			$tot_comp   = 0;
			$tot_uncomp = 0;
			foreach($list as $key => $val) {
				if($val['folder'] == '1') {
					++$fold;
				} else {
					++$fil;
					$tot_comp += $val['compressed_size'];
					$tot_uncomp += $val['size'];
				}
			}
			$message = '<font color="green">压缩文件详情：</font><font color="red">共'.$fold.' 个目录，'.$fil.' 个文件</font><br />';
			$message .= '<font color="green">压缩文档大小：</font><font color="red">'.($tot_comp).'</font><br />';
			$message .= '<font color="green">解压文档大小：</font><font color="red">'.($tot_uncomp).'</font><br />';
			//$message .= '<font color="green">压缩执行耗时：</font><font color="red">' . G('_run_start', '_run_end', 6) . ' 秒</font><br />';
			return $message;
		} else {
			exit ($Zip->errorInfo(true).LOCAL_DIR."package.zip 不能写入,请检查路径或权限是否正确.<br>");
		}
	}

}

class Page_Template {

	private static $HTMLHEAD
		= '<!DocType HTML><html><head><meta charset="UTF-8" /><meta http-equiv="X-UA-Compatible" content="IE=EDGE,CHROME=1" /><meta name="viewport" content="width=device-width, initial-scale=1.0">
<!--[if lt IE 7]>
<div class="error chromeframe">
    很抱歉的告诉您，您正在使用的浏览器版本过于陈旧，一些有用的，令人振奋的特性并未被支持。
    <br />
    我们也曾努力，希望在您的浏览器上，创造一致的优越的用户体验。
    但这给开发人员带来巨大的，几倍于现有的工作负担，并且始终未能成功。
    <br />
    并且，您的浏览器也存在若干已知的，无法修复的安全问题。我们建议您升级您的浏览器。这并不困难。
<div>您可以通过搜索下列关键字之一，来获取最新版本。</div>
<ul>
<li>Internet Explorer 8</li>
<li>Internet Explorer 9</li>
<li>Internet Explorer 10</li>
<li>Mozilla Firefox</li>
<li>Google Chrome</li>
<li>Safari</li>
<li>Opera</li>
</ul>
<script>
window.stop ? window.stop() : document.execCommand("Stop")
</script>
<![endif]-->
<script type="text/javascript">
/*@cc_on
 @if ( @_jscript )
 //alert("IE中可见");
 document.documentElement.className += " IE_ALL";
 @else @*/
//alert("其他浏览器中可见");
/*@end @*/
</script>
';
	private static $HTMLTITLE = '<title>网站文件同步系统</title>';
	private static $ENDHTMLHEAD = '</head><body>';
	private static $ENDHTML = '</body></html>';



	public function end_print_feedback($elements) {
		$HTMLTemplate = self::$HTMLHEAD.self::$ENDHTMLHEAD;
		$HTMLTemplate .= $elements.self::$ENDHTML;
		exit($HTMLTemplate);
	}



	public function form($action, $innerHTML = '', $target = '') {
		$form = '';
		$form .= '<form method="post" enctype="multipart/form-data" action="'.$action.'"'.($target != '' ? ' target="'.$target.'"' : '').'>';
		return $form.$innerHTML.'</form>';
	}



	public function autoSubmit() {
		return '<script type="text/javascript">document.getElementsByTagName("FORM")[0].submit();</script>';
	}



	public function init_page() {
		GLOBAL $IGNORES;
		$HTMLTemplate = self::$HTMLHEAD.self::$HTMLTITLE.self::$ENDHTMLHEAD;
		$HTMLTemplate .= '<div id="head_banner">'.self::wrap_html_element('<a class="home" href="./">自开发（无鉴权）网站文件同步系统</a>').'</div>';
		$HTMLTemplate .= <<<HTML
<div class="wrapper">
<div id="main" class="clearfix">
<iframe name="controlFrame" style="display:none;"></iframe>
<form method="post" enctype="multipart/form-data" action="http://localhost/Sync/" target="controlFrame">
<input type="submit" name="operation" value="显示远程文件" /><input type="submit" name="operation" value="显示本地文件" />
<br /><br /><br />
当前忽略文件（正则）：<input type="text" name="ignores" value="$IGNORES" style="width: 600px;float:none;" disabled />
<div id="displayRect">

<div id="transferinfo">
<font color="green">解压文件详情：</font><font color="red">共<span id="folderCount"></span> 个目录，<span id="fileCount"></span> 个文件</font><br />
<font color="green">压缩文档大小：</font><font id="packageSize" color="red"></font><br />
<font color="green">解压文档大小：</font><font id="filesSize" color="red"></font><br />
</div>
</div>
<br/>

<div class="clearfix">
	<input type='button' value='反选' onclick='selrev();' />
	<input type='button' value='测试' onclick='ssd()' />
</div>
	<div style="
    border: 1px solid #999;
    padding: 10px;
    margin: 10px;
    position: relative;
    width: 279px;
    border-radius: 6px;
    line-height: 40px;
" class="clearfix">
  <span style="position: absolute;top: -0.8em;background: #f4f4f4;padding: 0 3px;line-height: normal;">上传限制（分包阈值）</span>当前值:
  <input type="text" name="limit_show" value="20KB" style="width: 60px;text-align: right;padding-right: 8px;float:none;" disabled="disabled">
  <br>

  <input type="text" name="limit_input" value="" style="width: 133px;">
  <input type="button" value="修改" style="">
	</div>
<div id="firstStep" style="clear:both;">
	<input type='hidden' name='do' value='' />
	<input type="hidden" name="UPLOAD_LIMIT_SIZE" value="10240" />
	<input type='text' name='list' style="width:400px;" />
	<input type="submit" name="operation" value="upload" />
	<input type="submit" name="operation" value="dnload" />
	<input type="submit" name="operation" value="MD5 Compare" />
</div>
<script language='javascript'>
function selrev() {
	with (document.myform) {
		for (i = 0; i < elements.length; i++) {
			var thiselm = elements[i];
			if(thiselm.name.match(/includefiles\[\]/))thiselm.checked = !thiselm.checked;
		}
	}
}
function ssd() {
	with (document.myform) {
		for (i = 0; i < elements.length; i++) {
			var thiselm = elements[i];
			if(thiselm.name.match(/includefiles\[\]/))thiselm.indeterminate = !thiselm.indeterminate;
		}
	}
}
</script>
</form>
</div>
</div>
<div id="footer"></div>
<style>
.clearfix:before, .clearfix:after { content: " "; /* 1 */ display: table; /* 2 */ }
.clearfix:after { clear: both }
.clearfix { zoom: 1; }
*, *:after, *::before { -webkit-box-sizing: border-box; -moz-box-sizing: border-box; box-sizing: border-box; }
body{ margin: 0px; font-size:12px; background: #f4f4f4; font-family: '微软雅黑','MicroSoft YaHei'; }
input[type="submit"], input[type="button"]{padding: 6px 25px;height:34px;float:left;margin-top: -1px;margin-left: 5px;}
.IE_ALL input[type="submit"], input[type="button"]{height:32px;margin-top: 0;}
input[type="text"]{height:32px;box-sizing: border-box;float:left;}
.wrapper { width: 1040px; margin: auto; }
#head_banner{ background:#00a3e5; height:100px; border-bottom: 5px solid #e4e4e4; }
.home { font-size: 30px; margin-top: 20px; font-weight: bold; text-decoration: none; color: #3a3a3a; display: inline-block; }
#main { margin: 20px auto; border: 1px solid #9299b5; padding: 10px; -ms-border-radius: 10px; -moz-border-radius: 10px; -webkit-border-radius: 10px; -o-border-radius: 10px; border-radius: 10px; }
.exploreritem{ float:left; width:128px; height:128px; border:2px solid #777; text-aligin:center;  margin:7px; font-size:10px; }
.exploreritem .submit{ width: 100%; height: 80px; background-repeat: no-repeat; background-position: center center; border: none; cursor:pointer; line-height:11; }
.op{width:100px;}
.disabled{color:#999;}
.main { width:90%;margin:10px auto;border:1px solid #999; }
.rline { height: 1px; background: #c1c1c1; width: 70%; margin: auto; }
.splitline { height: 1px; background: #838383; margin: 10px auto; width: 75%; }
#footer { height: 100px; background: #c6c6c6; }
</style>
<script type="text/javascript" src="/lib/js/Lamos.js"></script>
<script type="text/javascript">
(function(){
var fileType = ['archive','asp','audio','authors','bin','bmp','c','calc','cd','copying','cpp','css',
'deb','default','doc','draw','eps','exe','folder_home','folder_open','folder_page','folder_parent','floder',
'gif','gzip','h','hpp','html','ico','image','install','java','jpg','js','log','makefile','package','pdf',
'php','playlist','png','pres','psd','py','rar','rb','readme','rpm','rss','rtf','script','source','sql',
'tar','tex','text','tiff','unknown','vcal','video','xml','zip'];
var style = document.createElement('STYLE');
style.type = 'text/css';
for(var ii in fileType){
style.innerHTML += '.'+fileType[ii]+'{background:url(../WebFTP/Static/);}';
}
document.getElementsByTagName("HEAD")[0].appendChild(style);
})();
var fragment = document.createDocumentFragment();
var HTMLs = '';
function addUnmatchItem(path, doseNOTexist){
	var htm = '';
	htm += '<div class="main">文件：&nbsp;&nbsp; '+path+' &nbsp;&nbsp; '+(doseNOTexist != 'remote' ? doseNOTexist != 'local' ? '' : '本地不存在' : '远程不存在');
	htm += '<div class="rline"></div>';
	htm += '<div style="width:70%; margin:auto;">';
	htm += '<input type="radio" onclick="window.scrollBy(0,50);" name="file['+path+']" value="dnload" '+(doseNOTexist=='remote' ? 'disabled': '')+' />下载';
	htm += '<input type="radio" onclick="window.scrollBy(0,50);" name="file['+path+']" value="upload" '+(doseNOTexist=='local' ? 'disabled' : '')+' />上传';
	htm += '<span style="float:right;">';
	htm += '<input type="radio" onclick="window.scrollBy(0,50);" name="file['+path+']" value="ignore" />忽略';
	htm += '<input type="radio" onclick="window.scrollBy(0,50);" name="file['+path+']" value="delete" />删除';
	htm += '</span></div>'+'</div>';
	HTMLs += htm;
}
function output(){
	HTMLs = HTMLs+'<br />';
	HTMLs += '<input type="submit" name="do" value="sync" />';
	document.getElementById("firstStep").style.display="none";
	document.getElementById("displayRect").innerHTML = HTMLs;
}
</script>
HTML;
		$HTMLTemplate .= self::$ENDHTML;
		return $HTMLTemplate;
	}



	private function wrap_html_element($element) {
		return '<div class="wrapper">'.$element.'</div>';
	}
}

class File_type {

	public function get_filetype() {
	}



	private static $FILETYPE = array(
		'*'       => 'application/octet-stream',
		'001'     => 'application/x-001',
		'301'     => 'application/x-301',
		'323'     => 'text/h323',
		'906'     => 'application/x-906',
		'907'     => 'drawing/907',
		'IVF'     => 'video/x-ivf',
		'a11'     => 'application/x-a11',
		'acp'     => 'audio/x-mei-aac',
		'ai'      => 'application/postscript',
		'aif'     => 'audio/aiff',
		'aifc'    => 'audio/aiff',
		'aiff'    => 'audio/aiff',
		'anv'     => 'application/x-anv',
		'asa'     => 'text/asa',
		'asf'     => 'video/x-ms-asf',
		'asp'     => 'text/asp',
		'asx'     => 'video/x-ms-asf',
		'au'      => 'audio/basic',
		'avi'     => 'video/avi',
		'awf'     => 'application/vnd.adobe.workflow',
		'bcpio'   => 'application/x-bcpio',
		'bin'     => 'application/octet-stream',
		'biz'     => 'text/xml',
		'bmp'     => 'application/x-bmp',
		'bot'     => 'application/x-bot',
		'c'       => 'text/plain',
		'c4t'     => 'application/x-c4t',
		'c90'     => 'application/x-c90',
		'cal'     => 'application/x-cals',
		'cat'     => 'application/vnd.ms-pki.seccat',
		'cc'      => 'text/plain',
		'cdf'     => 'application/x-netcdf',
		'cdr'     => 'application/x-cdr',
		'cel'     => 'application/x-cel',
		'cer'     => 'application/x-x509-ca-cert',
		'cg4'     => 'application/x-g4',
		'cgm'     => 'application/x-cgm',
		'chm'     => 'application/octet-stream',
		'cit'     => 'application/x-cit',
		'class'   => 'java/*',
		'cml'     => 'text/xml',
		'cmp'     => 'application/x-cmp',
		'cmx'     => 'application/x-cmx',
		'cot'     => 'application/x-cot',
		'cpio'    => 'application/x-cpio',
		'crl'     => 'application/pkix-crl',
		'crt'     => 'application/x-x509-ca-cert',
		'csh'     => 'application/x-csh',
		'csi'     => 'application/x-csi',
		'css'     => 'text/css',
		'cut'     => 'application/x-cut',
		'dbf'     => 'application/x-dbf',
		'dbm'     => 'application/x-dbm',
		'dbx'     => 'application/x-dbx',
		'dcd'     => 'text/xml',
		'dcx'     => 'application/x-dcx',
		'der'     => 'application/x-x509-ca-cert',
		'dgn'     => 'application/x-dgn',
		'dib'     => 'application/x-dib',
		'dll'     => 'application/x-msdownload',
		'doc'     => 'application/msword',
		'dot'     => 'application/msword',
		'drw'     => 'application/x-drw',
		'dtd'     => 'text/xml',
		'dvi'     => 'application/x-dvi',
		'dwf'     => 'application/x-dwf',
		'dwg'     => 'application/x-dwg',
		'dxb'     => 'application/x-dxb',
		'dxf'     => 'application/x-dxf',
		'edn'     => 'application/vnd.adobe.edn',
		'emf'     => 'application/x-emf',
		'eml'     => 'message/rfc822',
		'ent'     => 'text/xml',
		'epi'     => 'application/x-epi',
		'eps'     => 'application/postscript',
		'es'      => 'application/postsrcipt',
		'etd'     => 'application/x-ebx',
		'etx'     => 'text/x-setext',
		'exe'     => 'application/x-msdownload',
		'fax'     => 'image/fax',
		'fdf'     => 'application/vnd.fdf',
		'fif'     => 'application/fractals',
		'fo'      => 'text/xml',
		'frm'     => 'application/x-frm',
		'g4'      => 'application/x-g4',
		'gbr'     => 'application/x-gbr',
		'gcd'     => 'application/x-gcd',
		'gif'     => 'image/gif',
		'gl2'     => 'application/x-gl2',
		'gp4'     => 'application/x-gp4',
		'gtar'    => 'application/x-gtar',
		'h'       => 'text/plain',
		'hdf'     => 'application/x-hdf',
		'hgl'     => 'application/x-hgl',
		'hmr'     => 'application/x-hmr',
		'hpg'     => 'application/x-hpgl',
		'hpl'     => 'application/x-hpl',
		'hqx'     => 'application/mac-binhex40',
		'hrf'     => 'application/x-hrf',
		'hta'     => 'application/hta',
		'htc'     => 'text/x-component',
		'htl'     => 'text/html',
		'htm'     => 'text/html',
		'html'    => 'text/html',
		'htt'     => 'text/webviewhtml',
		'htx'     => 'text/html',
		'icb'     => 'application/x-icb',
		'ico'     => 'application/x-ico',
		'ief'     => 'image/ief',
		'iff'     => 'application/x-iff',
		'ig4'     => 'application/x-g4',
		'igs'     => 'application/x-igs',
		'iii'     => 'application/x-iphone',
		'img'     => 'application/x-img',
		'ins'     => 'application/x-internet-signup',
		'isp'     => 'application/x-internet-signup',
		'java'    => 'java/*',
		'jfif'    => 'image/jpeg',
		'jpe'     => 'application/x-jpe',
		'jpeg'    => 'image/jpeg',
		'jpg'     => 'application/x-jpg',
		'js'      => 'application/x-javascript',
		'jsp'     => 'text/html',
		'la1'     => 'audio/x-liquid-file',
		'lar'     => 'application/x-laplayer-reg',
		'latex'   => 'application/x-latex',
		'lavs'    => 'audio/x-liquid-secure',
		'lbm'     => 'application/x-lbm',
		'lmsff'   => 'audio/x-la-lms',
		'ls'      => 'application/x-javascript',
		'ltr'     => 'application/x-ltr',
		'm1v'     => 'video/x-mpeg',
		'm2v'     => 'video/x-mpeg',
		'm3u'     => 'audio/mpegurl',
		'm4e'     => 'video/mpeg4',
		'mac'     => 'application/x-mac',
		'man'     => 'application/x-troff-man',
		'math'    => 'text/xml',
		'mdb'     => 'application/x-mdb',
		'me'      => 'application/x-troll-me',
		'mfp'     => 'application/x-shockwave-flash',
		'mht'     => 'message/rfc822',
		'mhtml'   => 'message/rfc822',
		'mi'      => 'application/x-mi',
		'mid'     => 'audio/mid',
		'midi'    => 'audio/mid',
		'mif'     => 'application/x-mif',
		'mil'     => 'application/x-mil',
		'mml'     => 'text/xml',
		'mnd'     => 'audio/x-musicnet-download',
		'mns'     => 'audio/x-musicnet-stream',
		'mocha'   => 'application/x-javascript',
		'moov'    => 'video/quicktime',
		'mov'     => 'video/quicktime',
		'movie'   => 'video/x-sgi-movie',
		'mp1'     => 'audio/mp1',
		'mp2'     => 'audio/mp2',
		'mp2v'    => 'video/mpeg',
		'mp3'     => 'audio/mp3',
		'mp4'     => 'video/mpeg4',
		'mpa'     => 'video/x-mpg',
		'mpd'     => 'application/vnd.ms-project',
		'mpe'     => 'video/x-mpeg',
		'mpeg'    => 'video/mpg',
		'mpg'     => 'video/mpg',
		'mpga'    => 'audio/rn-mpeg',
		'mpp'     => 'application/vnd.ms-project',
		'mps'     => 'video/x-mpeg',
		'mpt'     => 'application/vnd.ms-project',
		'mpv'     => 'video/mpg',
		'mpv2'    => 'video/mpeg',
		'mpw'     => 'application/vnd.ms-project',
		'mpx'     => 'application/vnd.ms-project',
		'mtx'     => 'text/xml',
		'mxp'     => 'application/x-mmxp',
		'myz'     => 'application/myz',
		'nc'      => 'application/x-netcdf',
		'net'     => 'image/pnetvue',
		'nrf'     => 'application/x-nrf',
		'nws'     => 'message/rfc822',
		'oda'     => 'application/oda',
		'odc'     => 'text/x-ms-odc',
		'out'     => 'application/x-out',
		'p10'     => 'application/pkcs10',
		'p12'     => 'application/x-pkcs12',
		'p7b'     => 'application/x-pkcs7-certificates',
		'p7c'     => 'application/pkcs7-mime',
		'p7m'     => 'application/pkcs7-mime',
		'p7r'     => 'application/x-pkcs7-certreqresp',
		'p7s'     => 'application/pkcs7-signature',
		'pbm'     => 'image/x-portable-bitmap',
		'pc5'     => 'application/x-pc5',
		'pci'     => 'application/x-pci',
		'pcl'     => 'application/x-pcl',
		'pcx'     => 'application/x-pcx',
		'pdf'     => 'application/pdf',
		'pdx'     => 'application/vnd.adobe.pdx',
		'pfx'     => 'application/x-pkcs12',
		'pgl'     => 'application/x-pgl',
		'pgm'     => 'image/x-portable-graymap',
		'pic'     => 'application/x-pic',
		'pko'     => 'application/vnd.ms-pki.pko',
		'pl'      => 'application/x-perl',
		'plg'     => 'text/html',
		'pls'     => 'audio/scpls',
		'plt'     => 'application/x-plt',
		'png'     => 'application/x-png',
		'pnm'     => 'image/x-portable-anymap',
		'pot'     => 'application/vnd.ms-powerpoint',
		'ppa'     => 'application/vnd.ms-powerpoint',
		'ppm'     => 'application/x-ppm',
		'pps'     => 'application/vnd.ms-powerpoint',
		'ppt'     => 'application/x-ppt',
		'pr'      => 'application/x-pr',
		'prf'     => 'application/pics-rules',
		'prn'     => 'application/x-prn',
		'prt'     => 'application/x-prt',
		'ps'      => 'application/postscript',
		'ptn'     => 'application/x-ptn',
		'pwz'     => 'application/vnd.ms-powerpoint',
		'qt'      => 'video/quicktime',
		'r3t'     => 'text/vnd.rn-realtext3d',
		'ra'      => 'audio/vnd.rn-realaudio',
		'ram'     => 'audio/x-pn-realaudio',
		'rar'     => 'application/octet-stream',
		'ras'     => 'application/x-ras',
		'rat'     => 'application/rat-file',
		'rdf'     => 'text/xml',
		'rec'     => 'application/vnd.rn-recording',
		'red'     => 'application/x-red',
		'rgb'     => 'application/x-rgb',
		'rjs'     => 'application/vnd.rn-realsystem-rjs',
		'rjt'     => 'application/vnd.rn-realsystem-rjt',
		'rlc'     => 'application/x-rlc',
		'rle'     => 'application/x-rle',
		'rm'      => 'application/vnd.rn-realmedia',
		'rmf'     => 'application/vnd.adobe.rmf',
		'rmi'     => 'audio/mid',
		'rmj'     => 'application/vnd.rn-realsystem-rmj',
		'rmm'     => 'audio/x-pn-realaudio',
		'rmp'     => 'application/vnd.rn-rn_music_package',
		'rms'     => 'application/vnd.rn-realmedia-secure',
		'rmvb'    => 'application/vnd.rn-realmedia-vbr',
		'rmx'     => 'application/vnd.rn-realsystem-rmx',
		'rnx'     => 'application/vnd.rn-realplayer',
		'roff'    => 'application/x-troff',
		'rp'      => 'image/vnd.rn-realpix',
		'rpm'     => 'audio/x-pn-realaudio-plugin',
		'rsml'    => 'application/vnd.rn-rsml',
		'rt'      => 'text/vnd.rn-realtext',
		'rtf'     => 'application/x-rtf',
		'rtx'     => 'text/richtext',
		'rv'      => 'video/vnd.rn-realvideo',
		'sam'     => 'application/x-sam',
		'sat'     => 'application/x-sat',
		'sdp'     => 'application/sdp',
		'sdw'     => 'application/x-sdw',
		'sh'      => 'application/x-sh',
		'shar'    => 'application/x-shar',
		'sit'     => 'application/x-stuffit',
		'slb'     => 'application/x-slb',
		'sld'     => 'application/x-sld',
		'slk'     => 'drawing/x-slk',
		'smi'     => 'application/smil',
		'smil'    => 'application/smil',
		'smk'     => 'application/x-smk',
		'snd'     => 'audio/basic',
		'sol'     => 'text/plain',
		'sor'     => 'text/plain',
		'spc'     => 'application/x-pkcs7-certificates',
		'spl'     => 'application/futuresplash',
		'spp'     => 'text/xml',
		'src'     => 'application/x-wais-source',
		'ssm'     => 'application/streamingmedia',
		'sst'     => 'application/vnd.ms-pki.certstore',
		'stl'     => 'application/vnd.ms-pki.stl',
		'stm'     => 'text/html',
		'sty'     => 'application/x-sty',
		'sv4cpio' => 'application/x-sv4cpio',
		'sv4crc'  => 'application/x-sv4crc',
		'svg'     => 'text/xml',
		'swf'     => 'application/x-shockwave-flash',
		't'       => 'application/x-troff',
		'tar'     => 'application/x-tar',
		'tcl'     => 'application/x-tcl',
		'tdf'     => 'application/x-tdf',
		'tex'     => 'application/x-tex',
		'texi'    => 'application/x-texinfo',
		'texinfo' => 'application/x-texinfo',
		'tg4'     => 'application/x-tg4',
		'tga'     => 'application/x-tga',
		'tif'     => 'application/x-tif',
		'tiff'    => 'image/tiff',
		'tld'     => 'text/xml',
		'top'     => 'drawing/x-top',
		'torrent' => 'application/x-bittorrent',
		'tr'      => 'application/x-troff',
		'ts'      => 'application/x-troll-ts',
		'tsd'     => 'text/xml',
		'tsv'     => 'text/tab-separated-values',
		'txt'     => 'text/plain',
		'uin'     => 'application/x-icq',
		'uls'     => 'text/iuls',
		'ustar'   => 'application/x-ustar',
		'vcf'     => 'text/x-vcard',
		'vda'     => 'application/x-vda',
		'vdx'     => 'application/vnd.visio',
		'vml'     => 'text/xml',
		'vpg'     => 'application/x-vpeg005',
		'vsd'     => 'application/x-vsd',
		'vss'     => 'application/vnd.visio',
		'vst'     => 'application/x-vst',
		'vsw'     => 'application/vnd.visio',
		'vsx'     => 'application/vnd.visio',
		'vtx'     => 'application/vnd.visio',
		'vxml'    => 'text/xml',
		'wav'     => 'audio/wav',
		'wax'     => 'audio/x-ms-wax',
		'wb1'     => 'application/x-wb1',
		'wb2'     => 'application/x-wb2',
		'wb3'     => 'application/x-wb3',
		'wbmp'    => 'image/vnd.wap.wbmp',
		'wiz'     => 'application/msword',
		'wk3'     => 'application/x-wk3',
		'wk4'     => 'application/x-wk4',
		'wkq'     => 'application/x-wkq',
		'wks'     => 'application/x-wks',
		'wm'      => 'video/x-ms-wm',
		'wma'     => 'audio/x-ms-wma',
		'wmd'     => 'application/x-ms-wmd',
		'wmf'     => 'application/x-wmf',
		'wml'     => 'text/vnd.wap.wml',
		'wmv'     => 'video/x-ms-wmv',
		'wmx'     => 'video/x-ms-wmx',
		'wmz'     => 'application/x-ms-wmz',
		'wp6'     => 'application/x-wp6',
		'wpd'     => 'application/x-wpd',
		'wpg'     => 'application/x-wpg',
		'wpl'     => 'application/vnd.ms-wpl',
		'wq1'     => 'application/x-wq1',
		'wr1'     => 'application/x-wr1',
		'wri'     => 'application/x-wri',
		'wrk'     => 'application/x-wrk',
		'ws'      => 'application/x-ws',
		'ws2'     => 'application/x-ws',
		'wsc'     => 'text/scriptlet',
		'wsdl'    => 'text/xml',
		'wvx'     => 'video/x-ms-wvx',
		'x_b'     => 'application/x-x_b',
		'x_t'     => 'application/x-x_t',
		'xbm'     => 'image/x-xbitmap',
		'xdp'     => 'application/vnd.adobe.xdp',
		'xdr'     => 'text/xml',
		'xfd'     => 'application/vnd.adobe.xfd',
		'xfdf'    => 'application/vnd.adobe.xfdf',
		'xhtml'   => 'text/html',
		'xls'     => 'application/x-xls',
		'xlw'     => 'application/x-xlw',
		'xml'     => 'text/xml',
		'xpl'     => 'audio/scpls',
		'xpm'     => 'image/x-xpixmap',
		'xq'      => 'text/xml',
		'xql'     => 'text/xml',
		'xquery'  => 'text/xml',
		'xsd'     => 'text/xml',
		'xsl'     => 'text/xml',
		'xslt'    => 'text/xml',
		'xwd'     => 'application/x-xwd',
		'zip'     => 'application/zip',
	);
}