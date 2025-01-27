<?php

/**

CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `active` int NOT NULL DEFAULT '0',
  `admin` int NOT NULL DEFAULT '0',
  `token` varchar(128),
  `tokenexpire` DATETIME,
  `rmGroupId` int NOT NULL,

PRIMARY KEY (`id`),
-- UNIQUE KEY `name` (`name`),
UNIQUE KEY `Email` (`email`),
INDEX rm_ind (rmGroupId),
    FOREIGN KEY (rmGroupId)
        REFERENCES regnum_communities(id)
);

*/

class SqlParameters{
    var $name;
    var $value;
    var $type;

    function __construct($n, $v, $t){
        $this->name = $n;
        $this->value = $v;
        $this->type = $t; //PDO::PARAM_INT, PDO::PARAM_STR
    }
};


class LoginHelper
{

  function __construct(){
  }

	// https://raw.githack.com/xcash/bootstrap-autocomplete/master/dist/latest/index.html
  function getRMgroupsJSON($s){
    global $connection;
	  
	$q = $connection->prepare("SELECT `id`, `name`, `group` FROM regnum_communities" . ($s == "" ? "" : " WHERE `name` COLLATE UTF8_GENERAL_CI like :prefix OR `name` COLLATE UTF8_GENERAL_CI like :prefix2 "));
	if ($s != ''){
		$q->bindValue(':prefix', $s.'%', PDO::PARAM_STR);  
		$q->bindValue(':prefix2', '% '.$s.'%', PDO::PARAM_STR);  
	}

	$q->execute();
    $result = $q->fetchAll(PDO::FETCH_ASSOC);

    $arr = [];
	foreach($result as $row){
		$arr[]= '{ "value": '.$row['id'].', "text": '.json_encode($row['name']).' }';
	}
	$r = '[' . implode(',', $arr) . ']';
	return $r;
  }

  function get_by_email($email, &$d){
	  global $connection;
	  
	$stmt = $connection->prepare("SELECT * FROM users WHERE email=:email");
	$stmt->bindValue(':email', $email, PDO::PARAM_STR);  
	$stmt->execute();
    if ($stmt->rowCount() > 0){
      $d = $stmt->fetch(PDO::FETCH_ASSOC);
      return true;
    }else
      return false;
  }


  function confirmReg(&$d){ 
  	global $connection, $config;

    $stmt = $connection->prepare("SELECT admin, id, name FROM users WHERE token=:token AND tokenexpire > NOW()");
	$stmt->bindValue(':token', $d['token'], PDO::PARAM_STR);  
	$stmt->execute();

    if ($stmt->rowCount() > 0){
      $r = $stmt->fetch(PDO::FETCH_ASSOC);

	  $_SESSION['login'] = ($r['admin'] == 1 ? 'admin' : 'normal');
	  $_SESSION['user_id'] = $r['id'];
	  $_SESSION['name'] = $r['name'];
	
    //remove token
		$stmt = $connection->prepare("UPDATE users SET active=1,token=NULL,tokenexpire=NULL WHERE token=:token");
		$stmt->bindValue(':token', $d['token'], PDO::PARAM_STR);  
		$stmt->execute();

//      printr("Most már beléphet az új jelszavával.");
      return true;
    }else{
      printr(t('InvalidToken'));
    }
    $this->logout();
    $_SESSION['login'] = "denied";
    return false; 	  
  }

  function add(&$d) {
	global $connection, $config;
	
	if ($this->get_by_email($d['email'], $a)){
		$this->registrationForm(t('EmailTaken'), $d);
		exit;
		return 0;
	}

    $stmt = $connection->prepare("INSERT into users (email, password, admin, name, token, tokenexpire, rmgroupid) VALUES (:email, :password, :admin, :name, :token, ADDDATE(NOW(), INTERVAL 3 DAY), :rmgroupid)");
	$stmt->bindValue(':email', $d['email'], PDO::PARAM_STR);  
	$stmt->bindValue(':password', crypt($d['password'], $config['authentication']['salt']), PDO::PARAM_STR);  
	$stmt->bindValue(':admin', 0, PDO::PARAM_INT);  // $d['level']
	$stmt->bindValue(':name', $d['name'], PDO::PARAM_STR);  
	$token = $this->generateRandomToken();
	$stmt->bindValue(':token', $token, PDO::PARAM_STR);  
	$stmt->bindValue(':rmgroupid', $d['rmgroupid'], PDO::PARAM_INT);  
	
	$stmt->execute();

	//$next_page = GetParam($_REQUEST, "next_page");
	$body = t('RegConfirmationEmail');
	$url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?task=confirm&token=' . $token; // $next_page
	$url = "<a href='$url'>$url</a>";
	$body = str_replace("{url}", $url, $body);
	$body = str_replace("{name}", $d["name"], $body);
	$this->send_email("noreply@" . $_SERVER['HTTP_HOST'], $d["email"], t('LostPassword_Subject'), $body);
	//echo $body . $d["email"];
	printr(t('RegConfirmationSent'));

    return 1;
  }

  function authenticated_user(){
    if (isset($_SESSION['login']) && ($_SESSION['login'] != "denied"))
      return true;
    return false;
  }

  function get_access_level($level){
    global $config;

   if (!$config['loginHelper']) return true;

    if (isset($_SESSION['login']) &&
      ($_SESSION['login'] == $level ||
      $_SESSION['login'] == 'admin'))
      return true;
    return false;
  }

  function update(&$d) {
    global $config, $connection;

    if ($this->authenticated_user() && $d['user_id'] != $_SESSION['user_id'] && $this->get_access_level('admin') == false){
      // nem admin, de mas adatait akarja modositani
		printr(t("AccessDenied"));
		return 0;
    }
    
    $v = array();
    $p = array();
    if (isset($d['email'])) {
      $d['email'] = trim($d['email']);
      $v[]= "email=:email";
      $p[]= new SqlParameters(':email', $d['email'], PDO::PARAM_STR);
    }
    if (isset($d['password']) && $d['password'] != '') {
      $v[]= "password=:password";
      $p[]= new SqlParameters(':password', crypt($d['password'], $config['authentication']['salt']), PDO::PARAM_STR);
    }
    if (isset($d['name'])){
      $v[]= "name=:name";
      $p[]= new SqlParameters(':name', $d['name'], PDO::PARAM_STR);
    }
    if (isset($d['token'])){
      $v[]= "token=:token";
      $v[]= "tokenexpire=ADDDATE(NOW(), INTERVAL 20 HOUR)";
      $p[]= new SqlParameters(':token', $d['token'], PDO::PARAM_STR);
    }
    if (isset($d['level']) && $this->get_access_level('admin')){
      $v[]= "admin=:admin";
      $p[]= new SqlParameters(':admin', ($d['level'] == 'admin' ? 1 : 0), PDO::PARAM_INT);
    }
    $p[]= new SqlParameters(':id', intval($d['user_id']), PDO::PARAM_INT);

    $q = $connection->prepare("UPDATE users SET " . implode(', ', $v) . " WHERE id=:id");
	foreach($p as $param){  
		$q->bindValue($param->name, $param->value, $param->type);                                                                         }        
	$q->execute();
    return 1;
  }

  // function delete($id) {
    // $this->db->query("DELETE FROM users WHERE id = " . intval($id));
  // }


	/**
	"Én 3 vagy négy, inkább három korosztályt csinálnék: 
	- kicsik, akinek egyszerűbb kell: 14 évig
	- standard akik azért már képesek: 15-40
	- felnőttek, akik a vérkeringésbe annyira nincsenek benne, de lehet rájuk számítni: 40+"
	*/
  function countLevelByAge($i){
	  if ($i<15) 
		  return 1;
	  if ($i<41) 
		  return 2;
	  return 3;
  }

  function login(&$d){
	  global $connection, $config;

    $stmt = $connection->prepare("SELECT u.password, u.admin, u.id, u.name as username, rm.name as groupname, rm.group, rm.localRM, rm.averAge FROM users u left join regnum_communities rm ON u.rmGroupId=rm.id WHERE email=:email");
	$stmt->bindValue(':email', $d['email'], PDO::PARAM_STR);  
	$stmt->execute();
	
    if ($stmt->rowCount() > 0){
      $r = $stmt->fetch(PDO::FETCH_ASSOC);
//      var_dump($r);
      if ($r['password'] == crypt($d['password'], $config['authentication']['salt'])){
		  $_SESSION['login'] = ($r['admin'] == 1 ? 'admin' : 'normal');
		  $_SESSION['user_id'] = $r['id'];
		  $_SESSION['name'] = $r['username'];
		  
		  $result = [];
		  $result['ok'] = true;
		  $result['id'] = $r['id'];
		  $result['name'] = $r['username'];
		  $result['admin'] = $r['admin'] == 1;
		  $result['group'] = $r['groupname'];
		  $result['group2'] = $r['group'];
		  $result['level'] = $this->countLevelByAge($r['averAge']);
		  $_SESSION['user'] = $result;
		  
		  return true;
      }
    }
    $this->logout();
    $_SESSION['login'] = "denied";
    return false;
  }

  function logout(){
    if (isset($_SESSION['login'])) unset($_SESSION['login']);
    if (isset($_SESSION['user_id'])) unset($_SESSION['user_id']);
	if (isset($_SESSION['user'])) unset($_SESSION['user']);
  }

  function generateRandomToken($length = 32) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
  }

  /* elfelejtett jelszo esete */
  function login_reset($token){

	global $connection;

    $stmt = $connection->prepare("SELECT id, name FROM users WHERE token=:token AND tokenexpire > NOW()");
	$stmt->bindValue(':token', $token, PDO::PARAM_STR);  
	$stmt->execute();
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)){

      $this->modifyPasswordForm($token, t('AddNewPassword'));
 /*   should user login as well at this point? No, just modifies pwd.  
      $_SESSION['login'] = $r['level'];
      $_SESSION['user_id'] = $r['id'];
      $_SESSION['name'] = $r['name'];
*/

      return true;
    }else{
      printr(t('InvalidToken'));
    }
    $this->logout();
    $_SESSION['login'] = "denied";
    return false;
  }
  
  function login_reset_newPassword($token, $passw){
	  global $connection;
    
    $stmt = $connection->prepare("SELECT id FROM users WHERE token=:token AND tokenexpire > NOW()");
	$stmt->bindValue(':token', $token, PDO::PARAM_STR);  
	$stmt->execute();
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)){
      
      // set new password
      $r['password'] = $passw;
      $r['user_id'] = $r['id'];
      $this->update($r);
      //remove token
      $stmt = $connection->prepare("UPDATE users SET token=NULL,tokenexpire=NULL WHERE token=:token");
  	  $stmt->bindValue(':token', $token, PDO::PARAM_STR);  
	  $stmt->execute();
      $this->loginForm(t('NewPasswordSaved'));
    }
  }

  // function get_username(){
    // if (isset($_SESSION['name'])) return $_SESSION['name'];
    // return $_SERVER['REMOTE_ADDR']; //only ip address
  // }

  // function get_id(){
    // if (isset($_SESSION['user_id'])) return $_SESSION['user_id'];
    // return 0; //error
  // }

  function sendPassword(&$d){
    
    if (!empty($d["email"])){
      $info = array();
      if ($this->get_by_email($d["email"], $info)){

		$token = $this->generateRandomToken();
		$arr = array();
		$arr['user_id'] = $info['id'];
		$arr['token'] = $token;
		$this->update($arr);

		//$next_page = GetParam($_REQUEST, "next_page");
		$body = t('LostPassword_Email');
		$url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?task=reset&token=' . $token;
		$url = "<a href='$url'>$url</a>";
		$body = str_replace("{url}", $url, $body);
		$body = str_replace("{name}", $info["name"], $body);
		$this->send_email("noreply@" . $_SERVER['HTTP_HOST'], $d["email"], t('LostPassword_Subject'), $body);
		//echo $body . $d["email"];
		$this->loginForm(t('Password_Sent'));
		return true;
      }
    }
	$this->lostPasswordForm('', t('UnknownEmail'));
    return false;
  }

  function send_email($from, $to, $subject, $body){
    $headers = "From: <" . $from . ">\r\n"; //optional headerfields
    $headers .= 'Content-type: text/html; charset=utf-8' . "\r\n";
    //mail($to, $subject, $body, $headers);
	echo $body;
  }

  
  function loginForm($info='', $next_page='', $error=false, &$d=array()){
    global $page, $twig;
    
    $page->data['error'] = $error;
    $page->data['info'] = $info;
    $page->data['next_page'] = $next_page;
	$page->data['button_caption'] = t('Login');
	$page->data['task'] = 'login';
	if (count($d) > 0 && isset($d['email'])){
		$page->data['email'] = $d['email'];
	}
    echo $twig->render("rm.registration.twig", $page->data);
    exit;
  }

  function lostPasswordForm($info='', $error=false){
	global $page, $twig;

	$page->data['password'] = false;
	$page->data['info'] = $info;
	$page->data['error'] = $error;
	$page->data['button_caption'] = t('SendPassword');
	$page->data['task'] = 'sendPassword';
	$page->data['details'] = t('LostPasswordDetails');
	echo $twig->render("rm.registration.twig", $page->data);
	exit;
  }

  function registrationForm($info='', &$d=array()){
	global $page, $twig;

	$page->data['registration'] = true;
	$page->data['info'] = $info;
	$page->data['button_caption'] = t('Registration');
	$page->data['task'] = 'registration';
	if (count($d) > 0){
		$page->data['name'] = $d['name'];
		$page->data['rmgroupId'] = isset($d['rmgroupid']) ? $d['rmgroupid'] : '';
		$page->data['rmgroupName'] = isset($d['rmgroupid_text']) ? $d['rmgroupid_text'] : '';
	}
	echo $twig->render("rm.registration.twig", $page->data);
	exit;
  }

  
  function modifyPasswordForm($token, $info='', $next_page='', $error=false){
    global $page, $twig;
    
    $page->data['error'] = $error;
    $page->data['info'] = $info;
    $page->data['next_page'] = $next_page;
	$page->data['button_caption'] = t('OK');
	$page->data['task'] = 'modifyPassword';
	$page->data['token'] =  $token;
    echo $twig->render("rm.registration.twig", $page->data);
    exit;
  }


};
