<?php
//turn access
$this->ACL->turn(array($this->module, 'add_comments'));
$id = (int)$id;
if ($id < 1) redirect('/' . $this->module);


$target_entity = $this->Model->getById($id);
if (!$target_entity) redirect('/' . $this->module);
if (!$target_entity->getCommented()) return $this->showInfoMessage(__('Comments are denied here'), '/' . $this->module . '/view/' . $id); 


/* cut and trim values */
if (!empty($_SESSION['user'])) {
	$name = $_SESSION['user']['name'];
	$this->Register['Validate']->disableFieldCheck('login');
} else {
	$name = mb_substr($_POST['login'], 0, 70);
	$name = trim($name);
}


$mail = '';
$message = mb_substr($_POST['message'], 0, $this->Register['Config']->read('comment_lenght', $this->module));
$message = trim( $message );
$ip      = (!empty($_SERVER['REMOTE_ADDR'])) ? $_SERVER['REMOTE_ADDR'] : '';
$keystring = (isset($_POST['captcha_keystring'])) ? trim($_POST['captcha_keystring']) : '';


// Check fields
$errors = $this->Register['Validate']->check($this->getValidateRules());



// Check captcha if need exists	 
if (!$this->ACL->turn(array('other', 'no_captcha'), false)) {
	if (empty($keystring))                      
		$errors = $errors . '<li>' . __('Empty field "code"') . '</li>' . "\n";

	
	// ��������� ���� "���"
	if (!empty($keystring)) {
		// ��������� ���� "���" �� ������������ �������									
		if (!isset($_SESSION['captcha_keystring'])) {
			if (file_exists(ROOT . '/sys/logs/captcha_keystring_' . session_id() . '-' . date("Y-m-d") . '.dat')) {
				$_SESSION['captcha_keystring'] = file_get_contents(ROOT . '/sys/logs/captcha_keystring_' . session_id() . '-' . date("Y-m-d") . '.dat');
				@_unlink(ROOT . '/sys/logs/captcha_keystring_' . session_id() . '-' . date("Y-m-d") . '.dat');
			}
		}
		if (!isset($_SESSION['captcha_keystring']) || $_SESSION['captcha_keystring'] != $keystring)
			$error = $error.'<li>' . __('Wrong protection code') . '</li>'."\n";
	}
	unset($_SESSION['captcha_keystring']);
}


/* if an errors */
if (!empty($errors)) {
	$_SESSION['addCommentForm'] = array();
	$_SESSION['addCommentForm']['error'] = '<p class="errorMsg">' . __('Some error in form') . '</p>' .
		"\n" . '<ul class="errorMsg">' . "\n" . $errors . '</ul>' . "\n";
	$_SESSION['addCommentForm']['name'] = $name;
	$_SESSION['addCommentForm']['message'] = $message;
	redirect('/' . $this->module . '/view/' . $id);
}


/* SPAM DEFENCE */
if (isset($_SESSION['unix_last_post']) and (time()-$_SESSION['unix_last_post'] < 10)) {
	return $this->showInfoMessage(__('Your message has been added'), '/' . $this->module . '/view/' . $id);
} else {
	$_SESSION['unix_last_post'] = time();
}


/* remove cache */
$this->Cache->clean(CACHE_MATCHING_TAG, array('module_' . $this->module, 'record_id_' . $id));
$this->DB->cleanSqlCache();


/* save data */	
$data = array(
	'entity_id'   => $id,
	'name'     => $name,
	'message'  => $message,
	'ip'       => $ip,
	'user_id'  => (!empty($_SESSION['user']['id'])) ? $_SESSION['user']['id'] : 0,
	'date'     => new Expr('NOW()'),
	'mail'     => $mail,
	'module'   => $this->module,
);


$className = $this->Register['ModManager']->getEntityName('Comments');
$entityComm = new $className($data);
$commId = $entityComm->save();

if (!$commId) return $this->showInfoMessage(__('Some error occurred'), '/' . $this->module . '/view/' . $id);


$target_entity->setComments($target_entity->getComments() + 1);
$target_entity->save();

if ($this->Log) $this->Log->write('adding comment to ' . $this->module, $this->module . ' id(' . $id . ')');
return $this->showInfoMessage(__('Comment is added'), '/' . $this->module . '/view/' . $id );