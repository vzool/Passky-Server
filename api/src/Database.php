<?php
use PragmaRX\Google2FA\Google2FA;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once "Errors.php";
require_once "Display.php";
require_once "Settings.php";
require_once "User.php";
require_once "License.php";

require '../vendor/autoload.php';

class Database{

	public static function isUsernameTaken(string $username) : int{
		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("SELECT user_id FROM users WHERE username = :username");
			$stmt->bindParam(':username', $username, PDO::PARAM_STR);
			$stmt->execute();
			return ($stmt->rowCount() == 0) ? 0 : 1;
		}catch(PDOException $e) {
			return 505;
		}
		$conn = null;
	}

	public static function encryptPassword(string $password) : string{
		$algo = (defined('PASSWORD_ARGON2ID')) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
		$options = [
			'threads' => Settings::getCores(),
			'time_cost' => 4,
			'memory_cost' => 1<<16
		];
		return password_hash($password, $algo, $options);
	}

	public static function generateNonce() : string{
		$nonce = "";
		for($i = 0; $i < 5; $i++) $nonce .= random_int(100000,999999) . "p";
		$nonce = substr($nonce, 0, -1);
		return $nonce;
	}

	public static function generateCodes() : string{
		$codes = "";
		for($i = 0; $i < 10; $i++) $codes .= random_int(100000,999999) . ";";
		$codes = substr($codes, 0, -1);
		return $codes;
	}

	public static function getUserIpAddress() : string {
		if(!empty($_SERVER['HTTP_CLIENT_IP'])) return hash("sha256", $_SERVER['HTTP_CLIENT_IP'] . "passky2020" . date("Ymd"));
		if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return hash("sha256", $_SERVER['HTTP_X_FORWARDED_FOR'] . "passky2020" . date("Ymd"));
		return hash("sha256", $_SERVER['REMOTE_ADDR'] . "passky2020" . date("Ymd"));
	}

	public static function userSentToManyRequests(string $action) : bool{

		if(!Settings::getLimiter()) return false;

		$timerOptions = [
			'getInfo' => Settings::getLimiterGetInfo(),
			'getStats' => Settings::getLimiterGetStats(),
			'getToken' => Settings::getLimiterGetToken(),
			'createAccount' => Settings::getLimiterCreateAccount(),
			'getPasswords' => Settings::getLimiterGetPasswords(),
			'savePassword' => Settings::getLimiterSavePassword(),
			'importPasswords' => Settings::getLimiterImportPasswords(),
			'editPassword' => Settings::getLimiterEditPassword(),
			'deletePassword' => Settings::getLimiterDeletePassword(),
			'deletePasswords' => Settings::getLimiterDeletePasswords(),
			'deleteAccount' => Settings::getLimiterDeleteAccount(),
			'forgotUsername' => Settings::getLimiterForgotUsername(),
			'enable2fa' => Settings::getLimiterEnable2fa(),
			'disable2fa' => Settings::getLimiterDisable2fa(),
			'addYubiKey' => Settings::getLimiterAddYubiKey(),
			'removeYubiKey' => Settings::getLimiterRemoveYubiKey(),
			'upgradeAccount' => Settings::getLimiterUpgradeAccount()
		];

		$timer = $timerOptions[$action];
		if($timer < 1) return false;

		$ip = self::getUserIpAddress();
		$data = Settings::readLocalData($action . '_' . $ip);
		if($data != null){
			if((time() - $data) < $timer) return true;
		}

		Settings::writeLocalData($action . '_' . $ip, time(), $timer);
		return false;
	}

	public static function isYubiOTPValid(string $otp) : int{
		if(strlen($otp) != 44) return 0;

		$nonce = self::generateNonce();
		$result = file_get_contents(Settings::getYubiCloud() . '?id=' . Settings::getYubiId() . '&nonce=' . $nonce . '&otp=' . $otp . '&sl=secure&timestamp=1');

		if(str_contains($result, 'nonce=' . $nonce) && str_contains($result, 'status=OK')) return 1;
		return 0;
	}

	public static function is2FaValid(string $username, ?string $otp, ?string $secret, ?string $otps) : int {
		if($secret == null && $otps == null) return 1;
		if($otp == null) return 0;

		if(strlen($otp) == 6){
			if($secret == null) return 0;
			$google2fa = new Google2FA();
			return $google2fa->verifyKey($secret, $otp);
		}else if(strlen($otp) == 44){
			if($otps == null) return 0;
			if(!str_contains($otps, substr($otp, 0, 12))) return 0;
			return self::isYubiOTPValid($otp);
		}
		return 0;
	}

	public static function isTokenValid(string $username, string $token) : int{
		$username = strtolower($username);
		if($token == null || strlen($token) != 64) return 0;
		$userID = $username . '-' . self::getUserIpAddress();
		$data = Settings::readLocalData('token_' . $userID);
		if($data != null)
			if($data == $token) return 1;
		return 0;
	}

	public static function getUserCount() : int{
		$amount = Settings::readLocalData('user_count');
		if($amount != null) return $amount;

		$query = "SELECT COUNT(*) AS 'amount' FROM users";
		if(Settings::getEstimates()) $query = "SELECT TABLE_ROWS AS 'amount' FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '" . Settings::getDBName() . "' AND TABLE_NAME = 'users'";

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare($query);
			$stmt->execute();

			$amount = ($stmt->rowCount() == 1) ? $stmt->fetch()['amount'] : -1;
			$expiration = ($amount*5 >= 86400) ? 86400 : $amount*5+5;
			Settings::writeLocalData('user_count', $amount, $expiration);
			return $amount;
		}catch(PDOException $e) {
			Settings::writeLocalData('user_count', -1, 5);
			return -1;
		}
		$conn = null;
	}

	public static function getPasswordCount() : int{
		$amount = Settings::readLocalData('password_count');
		if($amount != null) return $amount;

		$query = "SELECT COUNT(*) AS 'amount' FROM passwords";
		if(Settings::getEstimates()) $query = "SELECT TABLE_ROWS AS 'amount' FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '" . Settings::getDBName() . "' AND TABLE_NAME = 'passwords'";

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare($query);
			$stmt->execute();

			$amount = ($stmt->rowCount() == 1) ? $stmt->fetch()['amount'] : -1;
			$expiration = ($amount*5 >= 86400) ? 86400 : $amount*5+5;
			Settings::writeLocalData('password_count', $amount, $expiration);
			return $amount;
		}catch(PDOException $e) {
			Settings::writeLocalData('password_count', -1, 5);
			return -1;
		}
		$conn = null;
	}

	public static function getUserPasswordCount($username) : int{

		$amount = Settings::readLocalData($username . '_password_count');
		if($amount != null) return $amount;

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("SELECT COUNT(*) AS 'amount' FROM passwords WHERE owner = :owner");
			$stmt->bindParam(':owner', $username, PDO::PARAM_STR);
			$stmt->execute();

			$amount = $stmt->fetch()['amount'];
			Settings::writeLocalData($username . '_password_count', $amount, 300);

			return ($stmt->rowCount() == 1) ? $amount : -1;
		}catch(PDOException $e) {
			Settings::writeLocalData($username . '_password_count', -1, 5);
			return -1;
		}
		$conn = null;
	}

	public static function getInfo() : string{
		$JSON_OBJ = new StdClass;
		$JSON_OBJ->version = Settings::getVersion();
		$JSON_OBJ->users = self::getUserCount();
		$JSON_OBJ->maxUsers = Settings::getMaxAccounts();
		$JSON_OBJ->passwords = self::getPasswordCount();
		$JSON_OBJ->maxPasswords = Settings::getMaxPasswords();
		$JSON_OBJ->location = Settings::getLocation();
		return Display::json(0, $JSON_OBJ);
	}

	public static function getStats() : string{

		$cpu = Settings::readLocalData('server_cpu');
		if($cpu == null){
			$cpu = sys_getloadavg()[0];
			Settings::writeLocalData('server_cpu', $cpu, 5);
		}

		$memoryUsed = Settings::readLocalData('server_memoryUsed');
		$memoryTotal = Settings::readLocalData('server_memoryTotal');
		if($memoryUsed == null || $memoryTotal == null){
			$free = shell_exec('free');
			$free = (string)trim($free);
			$free_arr = explode("\n", $free);
			$mem = explode(" ", $free_arr[1]);
			$mem = array_filter($mem, function($value) { return ($value !== null && $value !== false && $value !== ''); });
			$mem = array_merge($mem);

			$memoryUsed = $mem[2];
			$memoryTotal = $mem[1];

			Settings::writeLocalData('server_memoryUsed', $memoryUsed, 5);
			Settings::writeLocalData('server_memoryTotal', $memoryTotal, 5);
		}

		$diskUsed = Settings::readLocalData('server_diskUsed');
		$diskTotal = Settings::readLocalData('server_diskTotal');
		if($diskUsed == null || $diskTotal == null){
			$diskTotal = disk_total_space(".");
			$diskUsed = ($diskTotal - disk_free_space("."));

			Settings::writeLocalData('server_diskUsed', $diskUsed, 5);
			Settings::writeLocalData('server_diskTotal', $diskTotal, 5);
		}

		$JSON_OBJ = new StdClass;
		$JSON_OBJ->cpu = $cpu;
		$JSON_OBJ->cores = Settings::getCores();
		$JSON_OBJ->memoryUsed = $memoryUsed;
		$JSON_OBJ->memoryTotal = $memoryTotal;
		$JSON_OBJ->diskUsed = $diskUsed;
		$JSON_OBJ->diskTotal = $diskTotal;
		return Display::json(0, $JSON_OBJ);
	}

	public static function createAccount(string $username, string $password, string $email) : string{

		if(Settings::getMaxAccounts() > 0){
			$amount_of_accounts = self::getUserCount();
			if($amount_of_accounts == -1) return Display::json(505);
			if($amount_of_accounts >= Settings::getMaxAccounts()) return Display::json(15);
		}

		$sub_email = filter_var($email, FILTER_SANITIZE_EMAIL);

		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(12);
		if(!filter_var($sub_email, FILTER_VALIDATE_EMAIL)) return Display::json(6);
		if(!preg_match("/^[a-z0-9]{128}$/i", $password)) return Display::json(5);

		$username = strtolower($username);
		$email = strtolower($email);
		$encrypted_password = self::encryptPassword($password);

		switch(self::isUsernameTaken($username)){
			case 1:
				return Display::json(4);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		try{
			$conn = Settings::createConnection();
			$maxPasswords = Settings::getMaxPasswords();

			$stmt = $conn->prepare("INSERT INTO users(username, email, password, max_passwords) VALUES(:username, :email, :password, :max_passwords);");
			$stmt->bindParam(':username', $username, PDO::PARAM_STR);
			$stmt->bindParam(':email', $email, PDO::PARAM_STR);
			$stmt->bindParam(':password', $encrypted_password, PDO::PARAM_STR);
			$stmt->bindParam(':max_passwords', $maxPasswords, PDO::PARAM_INT);

			return ($stmt->execute()) ? Display::json(0) : Display::json(3);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function getToken(string $username, string $password, string $otp) : string{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(12);
		if(!preg_match("/^[a-z0-9]{128}$/i", $password)) return Display::json(5);
		$username = strtolower($username);

		$user = new User;
		$user->fromUsername($username);

		switch($user->response){
			case 1:
				return Display::json(1);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		if(self::is2FaValid($user->username, $otp, $user->secret, $user->yubico_otp) == 0) return Display::json(19);
		if(!password_verify($password, $user->password)) return Display::json(2);

		$userID = $username . '-' . self::getUserIpAddress();
		$token = Settings::readLocalData('token_' . $userID);
		if($token == null){
			$token = hash("sha256", self::generateCodes());
			Settings::writeLocalData('token_' . $userID, $token, 3600);
		}

		$today = date('Y-m-d');
		if($user->accessed != $today){
			try{
				$conn = Settings::createConnection();

				$stmt = $conn->prepare("UPDATE users SET accessed = :accessed WHERE username = :username");
				$stmt->bindParam(':username', $username, PDO::PARAM_STR);
				$stmt->bindParam(':accessed', $today, PDO::PARAM_STR);
				$stmt->execute();
			}catch(PDOException $e) {}
			$conn = null;
		}

		$JSON_OBJ = new StdClass;
		$JSON_OBJ->token = $token;
		$JSON_OBJ->auth = ($user->secret != null);
		$JSON_OBJ->yubico = $user->yubico_otp;
		$JSON_OBJ->max_passwords = $user->max_passwords;
		$JSON_OBJ->premium_expires = $user->premium_expires;

		$passwords = Settings::readLocalData($username . '_passwords');
		if($passwords != null){
			$passwords = unserialize($passwords);
			if(count($passwords) > 0){
				$JSON_OBJ->passwords = $passwords;
				return Display::json(0, $JSON_OBJ);
			}
			return Display::json(8, $JSON_OBJ);
		}

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("SELECT password_id AS id, website, username, password, message FROM passwords WHERE owner = :owner");
			$stmt->bindParam(':owner', $username, PDO::PARAM_STR);
			$stmt->execute();

			$passwords = $stmt->fetchAll(PDO::FETCH_ASSOC);
			Settings::writeLocalData($username . "_passwords", serialize($passwords), 60);

			if($stmt->rowCount() > 0){
				$JSON_OBJ->passwords = $passwords;
				return Display::json(0, $JSON_OBJ);
			}
			return Display::json(8, $JSON_OBJ);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function deletePasswords(string $username, string $token) : string{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		$username = strtolower($username);

		try{
			$conn = Settings::createConnection();
			Settings::removeLocalData($username . "_passwords");
			Settings::removeLocalData($username . '_password_count');

			$stmt = $conn->prepare("DELETE FROM passwords WHERE owner = :owner;");
			$stmt->bindParam(':owner', $username, PDO::PARAM_STR);
			return ($stmt->execute()) ? Display::json(0) : Display::json(11);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function deleteAccount(string $username, string $token) : string{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		$username = strtolower($username);

		try{
			$conn = Settings::createConnection();
			Settings::removeLocalData($username . '_data');

			$stmt = $conn->prepare("DELETE FROM passwords WHERE owner = :owner;");
			$stmt->bindParam(':owner', $username, PDO::PARAM_STR);
			if(!($stmt->execute())) return Display::json(11);

			$stmt = $conn->prepare("DELETE FROM users WHERE username = :username;");
			$stmt->bindParam(':username', $username, PDO::PARAM_STR);
			return ($stmt->execute()) ? Display::json(0) : Display::json(11);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function isPasswordOwnedByUser(string $username, int $password_id) : int{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return 3;

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("SELECT password_id FROM passwords WHERE owner = :owner AND password_id = :password_id");
			$stmt->bindParam(':owner', $username, PDO::PARAM_STR);
			$stmt->bindParam(':password_id', $password_id, PDO::PARAM_INT);
			$stmt->execute();

			return ($stmt->rowCount() == 1) ? 1 : 2;
		}catch(PDOException $e) {
			return 505;
		}
		$conn = null;
	}

	public static function savePassword(string $username, string $token, string $website, string $username2, string $password2, string $message) : string{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		$username = strtolower($username);

		$user = new User;
		$user->fromUsername($username);

		switch($user->response){
			case 1:
				return Display::json(1);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		if(!(strlen($website) >= 36 && strlen($website) <= 255) || str_contains($website, ' ')) return Display::json(300);
		if(!(strlen($username2) >= 36 && strlen($username2) <= 255) || str_contains($username2, ' ')) return Display::json(301);
		if(!(strlen($password2) >= 36 && strlen($password2) <= 255) || str_contains($password2, ' ')) return Display::json(302);
		if(!(strlen($message) >= 36 && strlen($message) <= 10000) || str_contains($message, ' ')) return Display::json(303);

		if($user->max_passwords >= 0){
			$password_count = self::getUserPasswordCount($username);
			if($password_count == -1) return Display::json(505);
			if($password_count >= $user->max_passwords) return Display::json(16);
		}

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("INSERT INTO passwords(owner, website, username, password, message) VALUES(:owner, :website, :username, :password, :message)");
			$stmt->bindParam(':owner', $username, PDO::PARAM_STR);
			$stmt->bindParam(':website', $website, PDO::PARAM_STR);
			$stmt->bindParam(':username', $username2, PDO::PARAM_STR);
			$stmt->bindParam(':password', $password2, PDO::PARAM_STR);
			$stmt->bindParam(':message', $message, PDO::PARAM_STR);

			Settings::removeLocalData($username . "_passwords");
			Settings::increaseLocalData($username . '_password_count', 1);

			return ($stmt->execute()) ? Display::json(0) : Display::json(3);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function importPasswords(string $username, string $token, string $json_passwords) : string{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		$username = strtolower($username);

		$password_obj = json_decode($json_passwords, true);
		if($password_obj === null && json_last_error() !== JSON_ERROR_NONE) return Display::json(14);

		$user = new User;
		$user->fromUsername($username);

		switch($user->response){
			case 1:
				return Display::json(1);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		if($user->max_passwords >= 0){
			$password_count = self::getUserPasswordCount($username);
			if($password_count == -1) return Display::json(505);
			if($password_count + count($password_obj) > $user->max_passwords) return Display::json(16);
		}

		$num_success = 0;
		$num_error = 0;

		$index = 0;
		$query = "INSERT INTO passwords(owner, website, username, password, message) VALUES";
		$passwordArray = array();

		foreach($password_obj as &$password_data){
			if(!(strlen($password_data["website"]) >= 36 && strlen($password_data["website"]) <= 255) || str_contains($password_data["website"], ' ')){ $num_error++; continue; }
			if(!(strlen($password_data["username"]) >= 36 && strlen($password_data["username"]) <= 255) || str_contains($password_data["username"], ' ')){ $num_error++; continue; }
			if(!(strlen($password_data["password"]) >= 36 && strlen($password_data["password"]) <= 255) || str_contains($password_data["password"], ' ')){ $num_error++; continue; }
			if(!(strlen($password_data["message"]) >= 36 && strlen($password_data["message"]) <= 10000) || str_contains($password_data["message"], ' ')){ $num_error++; continue; }

			$passwordArray[] = $password_data;
			$query .= "(:owner" . $index .", :website" . $index .", :username" . $index .", :password" . $index .", :message" . $index ."),";
			$index++;
		}
		$query = substr($query, 0, -1);

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare($query);
			for($i = 0; $i < $index; $i++){
				$stmt->bindParam(':owner'.$i, $username, PDO::PARAM_STR);
				$stmt->bindParam(':website'.$i, $passwordArray[$i]["website"], PDO::PARAM_STR);
				$stmt->bindParam(':username'.$i, $passwordArray[$i]["username"], PDO::PARAM_STR);
				$stmt->bindParam(':password'.$i, $passwordArray[$i]["password"], PDO::PARAM_STR);
				$stmt->bindParam(':message'.$i, $passwordArray[$i]["message"], PDO::PARAM_STR);
			}

			Settings::removeLocalData($username . "_passwords");

			if($stmt->execute()){
				$num_success = count($passwordArray);
				$num_error = count($password_obj) - count($passwordArray);
				Settings::increaseLocalData($username . '_password_count', $num_success);
			}else{
				$num_error = count($password_obj);
			}
		}catch(PDOException $e) {
			$num_error = count($password_obj);
		}
		$conn = null;

		$JSON_OBJ = new StdClass;
		$JSON_OBJ->import_success = $num_success;
		$JSON_OBJ->import_error = $num_error;
		return Display::json(0, $JSON_OBJ);
	}

	public static function editPassword(string $username, string $token, int $password_id, string $website, string $username2, string $password2, string $message) : string{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		$username = strtolower($username);

		if(!(strlen($website) >= 36 && strlen($website) <= 255) || str_contains($website, ' ')) return Display::json(300);
		if(!(strlen($username2) >= 36 && strlen($username2) <= 255) || str_contains($username2, ' ')) return Display::json(301);
		if(!(strlen($password2) >= 36 && strlen($password2) <= 255) || str_contains($password2, ' ')) return Display::json(302);
		if(!(strlen($message) >= 36 && strlen($message) <= 10000) || str_contains($message, ' ')) return Display::json(303);

		switch(self::isPasswordOwnedByUser($username, $password_id)){
			case 2:
				return Display::json(10);
			break;
			case 3:
				return Display::json(1);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("UPDATE passwords SET website = :website, username = :username, password = :password, message = :message WHERE password_id = :password_id");
			$stmt->bindParam(':website', $website, PDO::PARAM_STR);
			$stmt->bindParam(':username', $username2, PDO::PARAM_STR);
			$stmt->bindParam(':password', $password2, PDO::PARAM_STR);
			$stmt->bindParam(':message', $message, PDO::PARAM_STR);
			$stmt->bindParam(':password_id', $password_id, PDO::PARAM_INT);
			$stmt->execute();

			Settings::removeLocalData($username . "_passwords");

			return ($stmt->rowCount() == 1) ? Display::json(0) : Display::json(13);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function deletePassword(string $username, string $token, int $password_id) : string{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		$username = strtolower($username);

		switch(self::isPasswordOwnedByUser($username, $password_id)){
			case 2:
				return Display::json(10);
			break;
			case 3:
				return Display::json(1);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("DELETE FROM passwords WHERE password_id = :password_id");
			$stmt->bindParam(':password_id', $password_id, PDO::PARAM_INT);
			$stmt->execute();

			Settings::removeLocalData($username . "_passwords");
			Settings::decreaseLocalData($username . '_password_count', 1);

			return ($stmt->rowCount() == 1) ? Display::json(0) : Display::json(11);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function getPasswords(string $username, string $token) : string{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		$username = strtolower($username);

		$JSON_OBJ = new StdClass;

		$passwords = Settings::readLocalData($username . "_passwords");
		if($passwords != null){
			$passwords = unserialize($passwords);
			if(count($passwords) > 0){
				$JSON_OBJ->passwords = $passwords;
				return Display::json(0, $JSON_OBJ);
			}
			return Display::json(8, $JSON_OBJ);
		}

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("SELECT password_id AS id, website, username, password, message FROM passwords WHERE owner = :owner");
			$stmt->bindParam(':owner', $username, PDO::PARAM_STR);
			$stmt->execute();

			$passwords = $stmt->fetchAll(PDO::FETCH_ASSOC);
			Settings::writeLocalData($username . "_passwords", serialize($passwords), 60);

			if($stmt->rowCount() > 0){
				$JSON_OBJ->passwords = $passwords;
				return Display::json(0, $JSON_OBJ);
			}
			return Display::json(8, $JSON_OBJ);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function enable2Fa(string $username, string $token) : string{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		$username = strtolower($username);

		$user = new User;
		$user->fromUsername($username);

		switch($user->response){
			case 1:
				return Display::json(1);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		if($user->secret != null) return Display::json(26);

		$google2fa = new Google2FA();
		$secret = $google2fa->generateSecretKey();
		$codes = self::generateCodes();

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("UPDATE users SET 2fa_secret = :secret, backup_codes = :codes WHERE username = :username");
			$stmt->bindParam(':secret', $secret, PDO::PARAM_STR);
			$stmt->bindParam(':codes', $codes, PDO::PARAM_STR);
			$stmt->bindParam(':username', $username, PDO::PARAM_STR);
			$stmt->execute();

			Settings::removeLocalData($username . '_data');

			$JSON_OBJ = new StdClass;
			$JSON_OBJ->secret = $secret;
			$JSON_OBJ->qrcode = $google2fa->getQRCodeUrl("Passky", $username, $secret);
			$JSON_OBJ->codes = $codes;
			return Display::json(0, $JSON_OBJ);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function disable2Fa(string $username, string $token) : string{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		$username = strtolower($username);

		$user = new User;
		$user->fromUsername($username);

		switch($user->response){
			case 1:
				return Display::json(1);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		if($user->secret == null) return Display::json(27);

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("UPDATE users SET 2fa_secret = null WHERE username = :username");
			$stmt->bindParam(':username', $username, PDO::PARAM_STR);
			$stmt->execute();

			Settings::removeLocalData($username . '_data');

			return Display::json(0);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function addYubiKey(string $username, string $token, string $id){
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		if(strlen($id) != 44) return Display::json(23);
		$username = strtolower($username);

		$user = new User;
		$user->fromUsername($username);

		switch($user->response){
			case 1:
				return Display::json(1);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		if(!self::isYubiOTPValid($id)) return Display::json(23);
		$id = substr($id, 0, 12);

		if($user->yubico_otp == null){
			$yubico_otp = $id;
		}else{
			$yubi_keys = explode(';', $user->yubico_otp);
			if(count($yubi_keys) >= 5) return Display::json(20);
			if(in_array($id, $yubi_keys)) return Display::json(21);
			$yubico_otp = $user->yubico_otp . ";" . $id;
		}

		$codes = self::generateCodes();

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("UPDATE users SET yubico_otp = :yubico_otp, backup_codes = :backup_codes WHERE username = :username");
			$stmt->bindParam(':yubico_otp', $yubico_otp, PDO::PARAM_STR);
			$stmt->bindParam(':backup_codes', $codes, PDO::PARAM_STR);
			$stmt->bindParam(':username', $username, PDO::PARAM_STR);
			$stmt->execute();

			Settings::removeLocalData($username . '_data');

			$JSON_OBJ = new StdClass;
			$JSON_OBJ->yubico = $yubico_otp;
			$JSON_OBJ->codes = $codes;
			return Display::json(0, $JSON_OBJ);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function removeYubiKey(string $username, string $token, string $id){
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		if(strlen($id) != 44) return Display::json(23);
		$username = strtolower($username);

		$user = new User;
		$user->fromUsername($username);

		switch($user->response){
			case 1:
				return Display::json(1);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		if(!self::isYubiOTPValid($id)) return Display::json(23);
		$id = substr($id, 0, 12);

		if($user->yubico_otp == null) return Display::json(24);

		$yubi_keys = explode(';', $user->yubico_otp);
		if(!in_array($id, $yubi_keys)) return Display::json(24);

		$yubico_otp = str_replace(';' . $id, '', $user->yubico_otp);
		$yubico_otp = str_replace($id, '', $yubico_otp);

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("UPDATE users SET yubico_otp = :yubico_otp WHERE username = :username");
			$stmt->bindParam(':yubico_otp', $yubico_otp, PDO::PARAM_STR);
			$stmt->bindParam(':username', $username, PDO::PARAM_STR);
			$stmt->execute();

			Settings::removeLocalData($username . '_data');

			$JSON_OBJ = new StdClass;
			$JSON_OBJ->yubico = $yubico_otp;
			return Display::json(0, $JSON_OBJ);
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function forgotUsername(string $email) : string{
		if(!Settings::getMail()) return Display::json(28);
		$sub_email = filter_var($email, FILTER_SANITIZE_EMAIL);
		if(!filter_var($sub_email, FILTER_VALIDATE_EMAIL)) return Display::json(6);

		try{
			$conn = Settings::createConnection();

			$stmt = $conn->prepare("SELECT username FROM users WHERE email = :email");
			$stmt->bindParam(':email', $sub_email, PDO::PARAM_STR);
			$stmt->execute();

			if($stmt->rowCount() == 0) return Display::json(17);

			$message = "Usernames registered with your email: ";
			$html = "<p>Usernames registered with your email: <ul>";

			foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as &$array_username){
				$html .= "<li style='font-weight: bold;'>" . $array_username["username"] . "</li>";
				$message .= $array_username["username"] . ", ";
			}

			$html .= "</ul></p>";

			$mail = new PHPMailer(true);
			try {
				$mail->isSMTP();
				$mail->Host = Settings::getMailHost();
				$mail->SMTPAuth = true;
				$mail->Username = Settings::getMailUsername();
				$mail->Password = Settings::getMailPassword();
				$mail->SMTPSecure = (Settings::getMailTLS()) ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
				$mail->Port = Settings::getMailPort();

				$mail->setFrom(Settings::getMailUsername(), 'Passky');
				$mail->addAddress($email);
				$mail->addReplyTo(Settings::getMailUsername(), 'Passky');

				$mail->isHTML(true);
				$mail->Subject = 'Usernames under your email';
				$mail->Body = $html;
				$mail->AltBody = $message;

				if($mail->send()) return Display::json(0);
				return Display::json(506);
			} catch (Exception $e) {
				return Display::json(506);
			}
		}catch(PDOException $e) {
			return Display::json(505);
		}
		$conn = null;
	}

	public static function upgradeAccount(string $username, string $token, string $license) : string{
		if(!preg_match("/^[a-z0-9._]{6,30}$/i", $username)) return Display::json(1);
		if(!self::isTokenValid($username, $token)) return Display::json(25);
		if(strlen($license) != 29) return Display::json(29);
		$username = strtolower($username);

		$user = new User;
		$user->fromUsername($username);

		switch($user->response){
			case 1:
				return Display::json(1);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		$licenseObj = new License;
		$licenseObj->fromLicense($license);

		switch($licenseObj->response){
			case 29:
				return Display::json(29);
			break;
			case 505:
				return Display::json(505);
			break;
		}

		$today = date('Y-m-d');
		$premium = Settings::getPremium();
		if($licenseObj->linked != null) return Display::json(30);

		try{
			$conn = Settings::createConnection();

			$conn->beginTransaction();

			if($user->premium_expires == null){
				$expires = date('Y-m-d', strtotime($today . '+ ' . $licenseObj->duration . 'days'));
			}else{
				$expires = date('Y-m-d', strtotime($user->premium_expires . '+ ' . $licenseObj->duration . 'days'));
			}

			$stmt = $conn->prepare("UPDATE users SET max_passwords = :max_passwords, premium_expires = :premium_expires WHERE username = :username");
			$stmt->bindParam(':username', $username, PDO::PARAM_STR);
			$stmt->bindParam(':max_passwords', $premium, PDO::PARAM_INT);
			$stmt->bindParam(':premium_expires', $expires, PDO::PARAM_STR);
			$stmt->execute();

			$stmt = $conn->prepare("UPDATE licenses SET used = :used, linked = :linked WHERE license = :license");
			$stmt->bindParam(':license', $license, PDO::PARAM_STR);
			$stmt->bindParam(':used', $today, PDO::PARAM_STR);
			$stmt->bindParam(':linked', $username, PDO::PARAM_STR);
			$stmt->execute();

			$conn->commit();

			for($i = 1; $i <= 10; $i++) Settings::removeLocalData('admin_licenses_page_' . $i);
			Settings::removeLocalData('admin_licenses_count');
			Settings::removeLocalData($username . '_data');

			$JSON_OBJ = new StdClass;
			$JSON_OBJ->max_passwords = $premium;
			$JSON_OBJ->premium_expires = $expires;
			return Display::json(0, $JSON_OBJ);
		}catch(PDOException $e) {
			$conn->rollBack();
			return Display::json(505);
		}
		$conn = null;
	}

}
?>