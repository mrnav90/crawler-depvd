<?php

require_once(dirname(__FILE__).'/libs/simpleHtmlDom.php');
require_once(dirname(__FILE__).'/libs/Inflector.php');

define('CRAWLER_URL','http://www.depvd.com/');
define('UPLOAD_PATH',dirname(__FILE__).'/photos/');
define('SERVER_NAME','localhost');
define('DATABASE_NAME','crawler_depvd');
define('USER_NAME','root');
define('PASSWORD', '');

function connection(){
	try{
		$connection = mysqli_connect(SERVER_NAME, USER_NAME, PASSWORD, DATABASE_NAME);
		@mysqli_set_charset($connection, "utf8");
		$error = mysqli_error($connection);
		if(!empty($error)){
			throw new Exception('Connect error!!!');
		}
	}catch(Exception $ex){
		die($ex->getMessage());
	}
	return $connection;
}

function downloadMultipleFiles($urls, $dir)
{
	set_time_limit(0);
	$multi_handle = curl_multi_init();  
	$file_pointers = array();  
	$curl_handles = array();  
	foreach ($urls as $key => $url) {
		$ext = pathinfo($url, PATHINFO_EXTENSION); 
		$newName = md5(uniqid(32)).".".$ext;
	  	$file = $dir.'/'.$newName;
	  	if(!is_file($file)){  
	    	$curl_handles[$key] = curl_init($url);  
	    	$file_pointers[$key] = fopen ($file, "w");  
	    	curl_setopt($curl_handles[$key], CURLOPT_FILE, $file_pointers[$key]);  
	    	curl_setopt($curl_handles[$key], CURLOPT_HEADER , 0);  
	    	curl_setopt($curl_handles[$key], CURLOPT_CONNECTTIMEOUT, 60);  
	    	curl_multi_add_handle($multi_handle, $curl_handles[$key]);  
	  	}  
	}  
	  
	do {  
	  curl_multi_exec($multi_handle,$running);  
	}  
	while($running > 0);  
	  
	foreach ($urls as $key => $url) {
		if (isset($curl_handles[$key])) {
			curl_multi_remove_handle($multi_handle, $curl_handles[$key]);  
		  	curl_close($curl_handles[$key]);  
		  	fclose($file_pointers[$key]);
		}  
	}  
	curl_multi_close($multi_handle); 
}

function getUrl($url){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $ip=rand(0,255).'.'.rand(0,255).'.'.rand(0,255).'.'.rand(0,255);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("REMOTE_ADDR: $ip", "HTTP_X_FORWARDED_FOR: $ip"));
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/".rand(3,5).".".rand(0,3)." (Windows NT ".rand(3,5).".".rand(0,2)."; rv:2.0.1) Gecko/20100101 Firefox/".rand(3,5).".0.1");
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

function formatNumberFromStr($str){
	if(strpos($str, 'k') !==false && strpos($str, '.') !==false){
		$str = explode('.', $str);
		$first = (int)current($str) * 1000;
		$last = (int)str_replace('k', '', end($str)) * 100;
		return $first + $last;
	}else if(strpos($str, 'k')!==false){
		return str_replace('k', '', $str) * 1000;
	}else{
		return $str;
	}
}

function formatDateFromStr($str){
	$str = str_replace(' ,', '', $str);
	$date = date("Y-m-d",strtotime($str));
	return $date;
}

function getItems($url){
	$items = array();
	$dom = str_get_html(getUrl($url));
	foreach($dom->find('.vd-topic') as $item){
		$items[] = array(
			'title' => $item->find('.vd-topic-title > a > span',0)->plaintext,
			'link' => $item->find('.vd-topic-title > a',0)->href,
			'views' => formatNumberFromStr($item->find('.vd-topic-count > ul > li',0)->plaintext),
			'likes' => formatNumberFromStr($item->find('.vd-topic-count > ul > li',1)->plaintext),
			'created' => formatDateFromStr($item->find('.vd-topic-info > .vd-user > span',0)->plaintext)
		);
	}
	return $items;
}

function getItem($url){
	$items = array();
	$dom = str_get_html(getUrl($url));
	foreach($dom->find('.carousel-inner > .item') as $item){
		$items[] = $item->find('img',0)->getAttribute('data-original');
	}
	return $items;
}

function savePhotos($url, $postId){
	$item = getItem($url);
	if(!empty($item)){
		foreach($item as $value){
			$image = @file_get_contents($value);
			if(!empty($image)){
				$ext = pathinfo($value, PATHINFO_EXTENSION);
				$photo = strtolower(md5(uniqid(32)."".time()).".".$ext);
				$image = addslashes($image);
				connection()->query("INSERT INTO photos(name, image, post_id) 
									VALUES(
										'".$photo."',
										'".$image."',
										".$postId.")
							        ");
			}
		}
	}
}

function crawlerPages(){
	try{
		$connection = connection();
		$task = $connection->query("SELECT * FROM crawler_task")->fetch_object();
		if($task->currentCrawler < $task->totalCrawler){
			$urlRequest = CRAWLER_URL.'p'.$task->currentCrawler;
			$items = getItems($urlRequest);
			if(!empty($items)){
				foreach($items as $item){
					$connection->query("INSERT INTO posts(title, link, likePost, viewPost, created) 
					VALUES (
						'".$item['title']."',
						'".$item['link']."',
						".$item['likes'].",
						".$item['views'].",
						'".$item['created']."'
					)");
					$postId = mysqli_insert_id($connection);
					savePhotos($item['link'],$postId);
				}
				$connection->query("UPDATE crawler_task SET currentCrawler = currentCrawler+1");
			}
		}
	}catch(Exception $ex){
		die($ex->getMessage());
	}

}

function integratePhotos(){
	try{
		$connection = connection();
		$photos = $connection->query("SELECT * FROM photos WHERE isPutPhoto=0");
		while($photo = $photos->fetch_object()){
			$pathPhoto = UPLOAD_PATH.$photo->name;
			if(file_exists($pathPhoto)){

			}else{
				@file_put_contents($pathPhoto, $photo->image);
				$connection->query("UPDATE photos SET isPutPhoto=1 WHERE id=".$photo->id);
			}
		}
	}catch(Exception $ex){
		die($ex->getMessage());
	}
}

//crawlerPages();
// integratePhotos();


$c = connection()->query("UPDATE a SET b=1");
if($c){
echo "OK";
}











