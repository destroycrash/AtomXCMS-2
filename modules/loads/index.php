<?php
/*---------------------------------------------\
|											   |
| @Author:       Andrey Brykin (Drunya)        |
| @Email:        drunyacoder@gmail.com         |
| @Site:         http://fapos.net              |
| @Version:      1.9.3                         |
| @Project:      CMS                           |
| @package       CMS Fapos                     |
| @subpackege    Loads Module                  |
| @copyright     ©Andrey Brykin 2010-2013      |
| @last mod.     2013/08/04                    |
|----------------------------------------------|
|											   |
| any partial or not partial extension         |
| CMS Fapos,without the consent of the         |
| author, is illegal                           |
|----------------------------------------------|
| Любое распространение                        |
| CMS Fapos или ее частей,                     |
| без согласия автора, является не законным    |
\---------------------------------------------*/




Class LoadsModule extends Module {
	/**
	* @template  layout for module
	*/
	public $template = 'loads';
	/**
	* @module_title  title of module
	*/
	public $module_title = 'Каталог файлов';
	/**
	* @module module indentifier
	*/
	public $module = 'loads';
	
	/**
	* @module module indentifier
	*/
	public $attached_files_path = 'loads';

    /**
     * Wrong extention for download files
     */
    private $denyExtentions = array('.php', '.phtml', '.php3', '.html', '.htm', '.pl', '.PHP', '.PHTML', '.PHP3', '.HTML', '.HTM', '.PL', '.js', '.JS');




    public function __construct($params)
    {
		parent::__construct($params);
		$this->attached_files_path = ROOT . '/sys/files/' . $this->module . '/';
	}

	

	/**
     * @return none
     *
	 * default action ( show main page )
	 */
	public function index($tag = null)
    {
		
		//turn access
		$this->ACL->turn(array($this->module, 'view_list'));
		
		
		//формируем блок со списком  разделов
		$this->_getCatsTree();


        if ($this->cached && $this->Cache->check($this->cacheKey)) {
            $source = $this->Cache->read($this->cacheKey);
            return $this->_view($source);
        }


        // we need to know whether to show hidden
        $query_params = array('cond' => array());
        if (!$this->ACL->turn(array('other', 'can_see_hidden'), false)) {
            $query_params['cond']['available'] = 1;
        }
		if (!empty($tag)) $query_params['cond'][] = "`tags` LIKE '%{$tag}%'";


        $total = $this->Model->getTotal($query_params);
        list ($pages, $page) = pagination( $total, Config::read('per_page', $this->module), '/' . $this->module . '/');
        $this->Register['pages'] = $pages;
        $this->Register['page'] = $page;
        $this->page_title .= ' (' . $page . ')';


        $navi = array();
        $navi['add_link'] = ($this->ACL->turn(array($this->module, 'add_materials'), false))
            ? get_link(__('Add material'), '/' . $this->module . '/add_form/') : '';
        $navi['navigation'] = $this->_buildBreadCrumbs();
        $navi['pagination'] = $pages;
        $navi['meta'] = __('Count all material') . $total;
        $this->_globalize($navi);


        if($total <= 0) {
            $html = __('Materials not found');
            return $this->_view($html);
        }


        $params = array(
            'page' => $page,
            'limit' => $this->Register['Config']->read('per_page', $this->module),
            'order' => getOrderParam(__CLASS__),
        );
        $where = array();
        if (!$this->ACL->turn(array('other', 'can_see_hidden'), false)) $where['available'] = '1';
		if (!empty($tag)) $where[] = "`tags` LIKE '%{$tag}%'";
		

        $this->Model->bindModel('attaches');
        $this->Model->bindModel('author');
        $this->Model->bindModel('category');
        $records = $this->Model->getCollection($where, $params);

        if (is_object($this->AddFields) && count($records) > 0) {
            $records = $this->AddFields->mergeRecords($records);
        }


        foreach ($records as $entity) {
            $this->Register['current_vars'] = $entity;
            $markers = array();

            $markers['moder_panel'] = $this->_getAdminBar($entity);
            $entry_url = get_url(entryUrl($entity, $this->module));
            $markers['entry_url'] = $entry_url;


            $announce = $entity->getMain();
			
			
            $announce = $this->Textarier->getAnnounce($announce, $entry_url, 0,
                $this->Register['Config']->read('announce_lenght', $this->module), $entity);
			$announce = $this->insertImageAttach($entity, $announce);
			

            $markers['announce'] = $announce;


            $markers[$this->module] = $entity->getDownloads();
            $markers['profile_url'] = getProfileUrl($entity->getAuthor_id());
            $markers['category_url'] = get_url('/' . $this->module . '/category/' . $entity->getCategory_id());
			if ($entity->getTags()) $entity->setTags(explode(',', $entity->getTags())); 
            $entity->setAdd_markers($markers);


            //prepear cache tags
            $this->setCacheTag(array(
                'user_id_' . $entity->getAuthor_id(),
                'record_id_' . $entity->getId(),
            ));
        }


        $source = $this->render('list.html', array('entities' => $records));


        //write int cache
        if ($this->cached)
            $this->Cache->write($source, $this->cacheKey, $this->cacheTags);


        return $this->_view($source);
	}

	

	/**
	* action view category of loads
	*/
	public function category($id = null)
    {
        //turn access
        $this->ACL->turn(array($this->module, 'view_list'));
        $id = intval($id);
        if (empty($id) || $id < 1) redirect('/');


        $SectionsModel = $this->Register['ModManager']->getModelInstance($this->module . 'Sections');
        $category = $SectionsModel->getById($id);
        if (!$category)
            return showInfoMessage(__('Can not find category'), '/' . $this->module . '/');
        if (!$this->ACL->checkCategoryAccess($category->getNo_access()))
            return showInfoMessage(__('Permission denied'), '/' . $this->module . '/');


        $this->page_title = h($category->getTitle()) . ' - ' . $this->page_title;


        //формируем блок со списком  разделов
        $this->_getCatsTree($id);


        if ($this->cached && $this->Cache->check($this->cacheKey)) {
            $source = $this->Cache->read($this->cacheKey);
            return $this->_view($source);
        }

        // we need to know whether to show hidden
        $childCats = $SectionsModel->getOneField('id', array('parent_id' => $id));
        $childCats[] = $id;
        $childCats = implode(', ', $childCats);
        $query_params = array('cond' => array(
			'`category_id` IN (' . $childCats . ')'
        ));

        if (!$this->ACL->turn(array('other', 'can_see_hidden'), false)) {
            $query_params['cond']['available'] = 1;
        }


        $total = $this->Model->getTotal($query_params);
        list ($pages, $page) = pagination( $total, $this->Register['Config']->read('per_page', $this->module), '/' . $this->module . '/category/' . $id);
        $this->Register['pages'] = $pages;
        $this->Register['page'] = $page;
        $this->page_title .= ' (' . $page . ')';



        $navi = array();
        $navi['add_link'] = ($this->ACL->turn(array($this->module, 'add_materials'), false))
            ? get_link(__('Add material'), '/' . $this->module . '/add_form/') : '';
        $navi['navigation'] = $this->_buildBreadCrumbs($id);
        $navi['pagination'] = $pages;
        $navi['meta'] = __('Count material in cat') . $total;
        $navi['category_name'] = h($category->getTitle());
        $this->_globalize($navi);


        if($total <= 0) {
            $html = __('Materials not found');
            return $this->_view($html);
        }


        $params = array(
            'page' => $page,
            'limit' => Config::read('per_page', $this->module),
            'order' => getOrderParam(__CLASS__),
        );
        $where = $query_params['cond'];
        if (!$this->ACL->turn(array('other', 'can_see_hidden'), false)) $where['available'] = '1';


        $this->Model->bindModel('attaches');
        $this->Model->bindModel('author');
        $this->Model->bindModel('category');
        $records = $this->Model->getCollection($where, $params);


        if (is_object($this->AddFields) && count($records) > 0) {
            $records = $this->AddFields->mergeRecords($records);
        }


        // create markers
        foreach ($records as $result) {
            $this->Register['current_vars'] = $result;
            $markers = array();


            $markers['moder_panel'] = $this->_getAdminBar($result);
            $entry_url = get_url(entryUrl($result, $this->module));
            $markers['entry_url'] = $entry_url;
	
			
            $announce = $this->Textarier->getAnnounce($result->getMain()
                , $entry_url
                , 0
                , $this->Register['Config']->read('announce_lenght', $this->module)
                , $result
            );
			$announce = $this->insertImageAttach($result, $announce);


            $markers['announce'] = $announce;


            $markers['category_url'] = get_url('/' . $this->module . '/category/' . $result->getCategory_id());
            $markers['profile_url'] = getProfileUrl($result->getAuthor()->getId());


            //set users_id that are on this page
            $this->setCacheTag(array(
                'user_id_' . $result->getAuthor()->getId(),
                'record_id_' . $result->getId(),
            ));


            $result->setAdd_markers($markers);
        }


        $source = $this->render('list.html', array('entities' => $records));


        //write int cache
        if ($this->cached)
            $this->Cache->write($source, $this->cacheKey, $this->cacheTags);


        return $this->_view($source);
	}
	  
	 

	/**
	 * show page with load info
     * s
	 * @param int $id
	 * @return none
	 */
	public function view ($id = null)
    {
		//turn access
		$this->ACL->turn(array($this->module, 'view_materials'));
		$id = intval($id);
		if (empty($id) || $id < 1) redirect('/');



        $this->Model->bindModel('attaches');
        $this->Model->bindModel('author');
        $this->Model->bindModel('category');
        $entity = $this->Model->getById($id);


        if (empty($entity)) redirect('/error.php?ac=404');
        if ($entity->getAvailable() == 0 && !$this->ACL->turn(array('other', 'can_see_hidden'), false))
            return $this->showInfoMessage(__('Permission denied'), '/' . $this->module . '/');
        if (!$this->ACL->checkCategoryAccess($entity->getCategory()->getNo_access()))
            return $this->showInfoMessage(__('Permission denied'), '/' . $this->module . '/');


        // Some gemor with add fields
        if (is_object($this->AddFields)) {
            $entity = $this->AddFields->mergeRecords(array($entity));
            $entity = $entity[0];
        }


        $max_attaches = $this->Register['Config']->read('max_attaches', $this->module);
        if (empty($max_attaches) || !is_numeric($max_attaches)) $max_attaches = 5;


        //category block
        $this->_getCatsTree($entity->getCategory()->getId());
        /* COMMENT BLOCK */
        if (Config::read('comment_active', $this->module) == 1
            && $this->ACL->turn(array($this->module, 'view_comments'), false)
            && $entity->getCommented() == 1) {
            if ($this->ACL->turn(array($this->module, 'add_comments'), false))
                $this->comments_form = $this->_add_comment_form($id);
            $this->comments = $this->_get_comments($entity);
        }
        $this->Register['current_vars'] = $entity;
		


        //производим замену соответствующих участков в html шаблоне нужной информацией
        $this->page_title = h($entity->getTitle()) . ' - ' . $this->page_title;
        $tags = $entity->getTags();
        $description = $entity->getDescription();
        if (!empty($tags)) $this->page_meta_keywords = h($tags);
        if (!empty($description)) $this->page_meta_description = h($description);

        $navi = array();
        $navi['module_url'] = get_url('/' . $this->module . '/');
        $navi['category_url'] = get_url('/' . $this->module . '/category/' . $entity->getCategory()->getId());
        $navi['category_name'] = h($entity->getCategory()->getTitle());
        $navi['navigation'] = $this->_buildBreadCrumbs($entity->getCategory()->getId());
        $this->_globalize($navi);


		$markers = array();
		$markers['moder_panel'] = $this->_getAdminBar($entity);
		

		if($entity->getDownload() && is_file(ROOT . '/sys/files/' . $this->module . '/' . $entity->getDownload())) {
		  $attach_serv = '<a target="_blank" href="' . get_url('/' . $this->module . '/download_file/' 
		  . $entity->getId()) . '">' . __('Download from server') . ' ('.
		  ( getFileSize( ROOT . '/sys/files/' . $this->module . '/' . $entity->getDownload())) . ' Кб)</a>';
		} else {
			$attach_serv  = '';
		}
		
		if($entity->getDownload_url_size()) {
			$attach_rem_size = ' (' . getSimpleFileSize($entity->getDownload_url_size()) . ')';
		} else {
			$attach_rem_size  = '';
		}

		if($entity->getDownload_url()) {
		  $attach_rem_url = '<a target="_blank" href="' . get_url('/' . $this->module . '/download_file_url/' 
		  . $entity->getId()) . '">' . __('Download remotely') . $attach_rem_size . '</a>';
		} else {
			$attach_rem_url = '';
		}
		$markers['attachment'] = $attach_serv . ' | ' . $attach_rem_url;


        $announce = $entity->getMain();
        $announce = $this->Textarier->print_page($announce, $entity->getAuthor()->getStatus(), $entity->getTitle());
		$announce = $this->insertImageAttach($entity, $announce);
		

        $markers['mainText'] = $announce;
        $markers['main_text'] = $announce;
		
		

		$entry_url = get_url(entryUrl($entity, $this->module));
		$markers['entry_url'] = $entry_url;

		
		$markers['profile_url'] = getProfileUrl($entity->getAuthor_id());
        $entity->setAdd_markers($markers);
		if ($entity->getTags()) $entity->setTags(explode(',', $entity->getTags()));


        $source = $this->render('material.html', array('entity' => $entity));


        $entity->setViews($entity->getViews() + 1);
        $entity->save();
        $this->DB->cleanSqlCache();

        return $this->_view($source);
	}


	/**
	 * Show materials by user. User ID must be integer and not null.
	 */
	public function user($id = null) 
	{
		//turn access
		$this->ACL->turn(array($this->module, 'view_list'));
		$id = intval($id);
		if ($id < 1)
		return $this->showInfoMessage(__('Can not find user'), $this->getModuleURL());


		$usersModel = $this->Register['ModManager']->getModelInstance('Users');
		$user = $usersModel->getById($id);
		if (!$user)
			return $this->showInfoMessage(__('Can not find user'), $this->getModuleURL());
		if (!$this->ACL->checkCategoryAccess($user->getNo_access()))
			return $this->showInfoMessage(__('Permission denied'), $this->getModuleURL());


		$this->page_title = __('User materials') . ' "' . h($user->getName()) . '" - ' . $this->page_title;


		//формируем блок со списком разделов
		$this->_getCatsTree();


		if ($this->cached && $this->Cache->check($this->cacheKey)) {
			$source = $this->Cache->read($this->cacheKey);
			return $this->_view($source);
		}

		// we need to know whether to show hidden
		$where = array('author_id' => $id);
		if (!$this->ACL->turn(array('other', 'can_see_hidden'), false)) {
			$where['available'] = 1;
		}


		$total = $this->Model->getTotal(array('cond' => $where));
		list ($pages, $page) = pagination($total, $this->Register['Config']->read('per_page', $this->module), $this->getModuleURL('user/' . $id));
		$this->Register['pages'] = $pages;
		$this->Register['page'] = $page;
		$this->page_title .= ' (' . $page . ')';



		$navi = array();
		$navi['add_link'] = ($this->ACL->turn(array($this->module, 'add_materials'), false)) ? get_link(__('Add material'), $this->getModuleURL('add_form/')) : '';
		$navi['navigation'] = get_link(__('Home'), '/') . __('Separator')
		. get_link(h($this->module_title), $this->getModuleURL()) . __('Separator') . __('User materials') . ' "' . h($user->getName()) . '"';
		$navi['pagination'] = $pages;
		$navi['meta'] = __('Count all material') . $total;
		$navi['category_name'] = __('User materials') . ' "' . h($user->getName()) . '"';
		$this->_globalize($navi);


		if ($total <= 0) {
			$html = __('Materials not found');
			return $this->_view($html);
		}


		$params = array(
			'page' => $page,
			'limit' => $this->Register['Config']->read('per_page', $this->module),
			'order' => getOrderParam(__CLASS__),
		);


		$this->Model->bindModel('author');
		$this->Model->bindModel('category');
		$records = $this->Model->getCollection($where, $params);


		// create markers
		foreach ($records as $entity) {
			$this->Register['current_vars'] = $entity;
			$markers = array();


			$markers['moder_panel'] = $this->_getAdminBar($entity);
			$entry_url = get_url(entryUrl($entity, $this->module));
			$markers['entry_url'] = $entry_url;

			
			
			$announce = $this->Textarier->getAnnounce($entity->getMain(), $entry_url, 0, $this->Register['Config']->read('announce_lenght', $this->module), $entity);
			$announce = $this->insertImageAttach($entity, $announce);


			$markers['announce'] = $announce;

			

			$markers['category_url'] = get_url($this->getModuleURL('category/' . $entity->getCategory_id()));
			$markers['profile_url'] = getProfileUrl($entity->getAuthor_id());


			//set users_id that are on this page
			$this->setCacheTag(array(
			'user_id_' . $entity->getAuthor_id(),
			'record_id_' . $entity->getId(),
			'category_id_' . $id,
			));


			$entity->setAdd_markers($markers);
		}


		$source = $this->render('list.html', array('entities' => $records));


		//write int cache
		if ($this->cached)
			$this->Cache->write($source, $this->cacheKey, $this->cacheTags);


		return $this->_view($source);
	}
		

	
	/*
	 * return form to add 
	 */
	public function add_form () {
		//turn access
		$this->ACL->turn(array($this->module, 'add_materials'));
		$writer_status = (!empty($_SESSION['user']['status'])) ? $_SESSION['user']['status'] : 0;
		
		
		//формируем блок со списком  разделов
		$this->_getCatsTree();


        // Additional fields
        $markers = array();
        if (is_object($this->AddFields)) {
            $_addFields = $this->AddFields->getInputs(array(), true, $this->module);
            foreach($_addFields as $k => $field) {
                $markers[strtolower($k)] = $field;
            }
        }


        // Check for preview or errors
        $data = array('title' => null, 'mainText' => null, 'in_cat' => null, 'description' => null, 'tags' => null, 'sourse' => null, 'sourse_email' => null, 'sourse_site' => null, 'commented' => null, 'available' => null, 'download_url' => null, 'download_url_size' => null);
        $data = array_merge($data, $markers);
        $data = Validate::getCurrentInputsValues($data);


        $data['preview'] = $this->Parser->getPreview($data['mainText']);
        $data['errors'] = $this->Parser->getErrors();
        if (isset($_SESSION['viewMessage'])) unset($_SESSION['viewMessage']);
        if (isset($_SESSION['FpsForm'])) unset($_SESSION['FpsForm']);


		
        $SectionsModel = $this->Register['ModManager']->getModelInstance($this->module . 'Sections');
        $sql = $SectionsModel->getCollection();
        $data['cats_selector'] = $this->_buildSelector($sql, ((!empty($data['in_cat'])) ? $data['in_cat'] : false));


        //comments and hide
        $data['commented'] = (!empty($commented) || !isset($_POST['submitForm'])) ? 'checked="checked"' : '';
        if (!$this->ACL->turn(array($this->module, 'record_comments_management'), false)) $data['commented'] .= ' disabled="disabled"';
        $data['available'] = (!empty($available) || !isset($_POST['submitForm'])) ? 'checked="checked"' : '';
        if (!$this->ACL->turn(array($this->module, 'hide_material'), false)) $data['available'] .= ' disabled="disabled"';


		$data['action'] = get_url('/' . $this->module . '/add/');
		$data['max_attaches'] = Config::read('max_attaches', $this->module);
		if (empty($data['max_attaches']) || !is_numeric($data['max_attaches']))
			$data['max_attaches'] = 5;
			
			
		//navigation panel
		$navi = array();
		$navi['navigation'] = $this->_buildBreadCrumbs();
		$this->_globalize($navi); 
			
			
		$source = $this->render('addform.html', array('context' => $data));
		
		
		return $this->_view($source);
	}

	
	
	/**
	 * 
	 * Validate data and create a new record into 
	 * Data Base. If an errors, redirect user to add form
	 * and show error message where speaks as not to admit 
	 * errors in the future
	 *
     * @return none;
	 */
	public function add()
    {
		//turn access
		$this->ACL->turn(array($this->module, 'add_materials'));
		$errors  = '';


        // Check additional fields if an exists.
        // This must be doing after define $error variable.
        if (is_object($this->AddFields)) {
            $_addFields = $this->AddFields->checkFields();
            if (is_string($_addFields)) $errors .= $_addFields;
        }
		
		$errors .= $this->Register['Validate']->check($this->getValidateRules());


		$fields = array('description', 'tags', 'sourse', 'sourse_email', 'sourse_site', 'download_url', 'download_url_size');
		$fields_settings = $this->Register['Config']->read('fields', $this->module);
		foreach ($fields as $field) {
			if (empty($_POST[$field]) && in_array($field, $fields_settings)) {
				$$field = null;
			} else {
				$$field = trim($_POST[$field]);

			}
		}
		
		// Обрезаем переменные до длины, указанной в параметре maxlength тега input
		$title     = mb_substr(trim($_POST['title']), 0, 128 );
		$addLoad   = trim($_POST['mainText']);
		$title     = trim( $title );
		$in_cat    = intval($_POST['cats_selector']);
		$commented = (!empty($_POST['commented'])) ? 1 : 0;
		$available = (!empty($_POST['available'])) ? 1 : 0;
		if (!$this->ACL->turn(array($this->module, 'record_comments_management'), false)) $commented = '1';
		if (!$this->ACL->turn(array($this->module, 'hide_material'), false)) $available = '1';
		
		
		// Preview
		if ( isset( $_POST['viewMessage'] ) ) {
			$_SESSION['viewMessage'] = array_merge(array(
			'title' => null, 
			'mainText' => null, 
			'in_cat' => $in_cat, 
			'description' => null, 
			'tags' => null, 
			'sourse' => null, 
			'sourse_email' => null, 
			'sourse_site' => null, 
			'download_url' => null, 
			'download_url_size' => null, 
			'commented' => null, 
			'available' => null), $_POST);
			redirect('/' . $this->module . '/add_form/');
		}



        $sectionModel = $this->Register['ModManager']->getModelInstance($this->module . 'Sections');
        $section = $sectionModel->getById($in_cat);
        if (!$section) $errors .= '<li>' . __('Can not find category') . '</li>' . "\n";
		
		

		// Errors
		if (!empty($errors)) {
			$_SESSION['FpsForm'] = array_merge(array(
			'title' => null, 
			'mainText' => null, 
			'in_cat' => $in_cat,
			'description' => null, 
			'tags' => null, 
			'sourse' => null, 
			'sourse_email' => null, 
			'sourse_site' => null, 
			'download_url' => null, 
			'download_url_size' => null, 
			'commented' => null, 
			'available' => null), $_POST);
			$_SESSION['FpsForm']['error'] = '<p class="errorMsg">' . __('Some error in form') . '</p>'.
				"\n" . '<ul class="errorMsg">' . "\n" . $errors . '</ul>' . "\n";
			redirect('/' . $this->module . '/add_form/');
		}


		//Проверяем прикрепленный файл...
		$file = '';
		if (!empty($_FILES['attach_file']['name'])) {
			$file = $this->__saveFile($_FILES['attach_file']);
		}
		
		// span protected
		if ( isset( $_SESSION['unix_last_post'] ) and ( time() - $_SESSION['unix_last_post'] < 30 ) ) {
			return $this->showInfoMessage(__('Your message has been added'), '/' . $this->module . '/');
		}
		
		
		// Auto tags generation
		if (empty($tags)) {
			$TagGen = new MetaTags;
			$tags = $TagGen->getTags($addLoad);
			$tags = (!empty($tags) && is_array($tags)) ? implode(',', array_keys($tags)) : '';
		}
		
		
		// Формируем SQL-запрос на добавление темы
		$max_lenght = Config::read('max_lenght', $this->module);
		$addLoad = mb_substr($addLoad, 0, $max_lenght);
        $data = array(
            'title' 		=> $title,
            'main' 			=> $addLoad,
            'date' 			=> new Expr('NOW()'),
            'author_id' 	=> $_SESSION['user']['id'],
            'category_id' 	=> $in_cat,
            'download' 		=> $file,
            'description'   => $description,
            'tags'          => $tags,
            'sourse'  	    => $sourse,
            'sourse_email'  => $sourse_email,
            'sourse_site'   => $sourse_site,
            'download_url'   => $download_url,
            'download_url_size'   => (int)$download_url_size,
            'commented'     => $commented,
            'available'     => $available,
            'view_on_home' 	=> $section->getView_on_home(),
        );
        $entity = new LoadsEntity($data);
        $last_id = $entity->save();
		

        // Get last insert ID and save additional fields if an exists and activated.
        // This must be doing only after save main(parent) material
        if (is_object($this->AddFields)) {
            $this->AddFields->save($last_id, $_addFields);
        }

        downloadAttaches($this->module, $last_id);
		
		
		// hook for plugins
		Plugins::intercept('new_entity', array(
			'entity' => $entity,
			'module' => $this->module,
		));

		
        //clear cache
        $this->Cache->clean(CACHE_MATCHING_ANY_TAG, array('module_' . $this->module));
        $this->DB->cleanSqlCache();
        if ($this->Log) $this->Log->write('adding ' . $this->module, $this->module . ' id(' . $last_id . ')');
		
        return $this->showInfoMessage(__('Material successfully added'), '/' . $this->module . '/' );
	}



	/**
	 * 
	 * Create form and fill his data from record which ID
	 * transfered into function. Show errors if an exists
	 * after unsuccessful attempt.
	 * 
	 */
	public function edit_form($id = null)
    {
		$id = (int)$id;
		if ($id < 1 || empty($id)) redirect('/');


        $this->Model->bindModel('attaches');
        $this->Model->bindModel('author');
        $this->Model->bindModel('category');
        $entity = $this->Model->getById($id);

        if (!$entity) redirect('/' . $this->module . '/');


        if (is_object($this->AddFields) && count($entity) > 0) {
            $entity = $this->AddFields->mergeRecords(array($entity), true);
            $entity = $entity[0];
        }


        //turn access
        if (!$this->ACL->turn(array($this->module, 'edit_materials'), false)
            && (!empty($_SESSION['user']['id']) && $entity->getAuthor()->getId() == $_SESSION['user']['id']
                && $this->ACL->turn(array($this->module, 'edit_mine_materials'), false)) === false) {
            return $this->showInfoMessage(__('Permission denied'), '/' . $this->module . '/');
        }


        $this->Register['current_vars'] = $entity;

        //forming categories list
        $this->_getCatsTree($entity->getCategory()->getId());

		
        // Check for preview or errors
        $data = array(
            'title' => '',
            'mainText' => $entity->getMain(),
            'in_cat' => $entity->getCategory_id(),
            'description' => '',
            'tags' => '',
            'sourse' => '',
            'sourse_email' => '',
            'sourse_site' => '',
            'commented' => '',
            'available' => '',
            'download_url' => '',
            'download_url_size' => '',
        );
        $markers = Validate::getCurrentInputsValues($entity, $data);
        $markers->setMainText($data['mainText']);


        $markers->setPreview($this->Parser->getPreview($markers->getMain()));
        $markers->setErrors($this->Parser->getErrors());
        if (isset($_SESSION['viewMessage'])) unset($_SESSION['viewMessage']);
        if (isset($_SESSION['FpsForm'])) unset($_SESSION['FpsForm']);


		
		$sectionsModel = $this->Register['ModManager']->getModelInstance($this->module . 'Sections');
		$categories = $sectionsModel->getCollection();
		$selectedCatId = ($markers->getIn_cat()) ? $markers->getIn_cat() : $markers->getCategory_id();
		$cats_change = $this->_buildSelector($categories, $selectedCatId); 


        //comments and hide
        $commented = ($markers->getCommented()) ? 'checked="checked"' : '';
        if (!$this->ACL->turn(array($this->module, 'record_comments_management'), false)) $commented .= ' disabled="disabled"';
        $available = ($markers->getAvailable()) ? 'checked="checked"' : '';
        if (!$this->ACL->turn(array($this->module, 'hide_material'), false)) $available .= ' disabled="disabled"';
        $markers->setAction(get_url('/' . $this->module . '/update/' . $markers->getId()));
        $markers->setCommented($commented);
        $markers->setAvailable($available);



        $attaches = $markers->getAttaches();
        $attDelButtons = '';
        if (count($attaches)) {
            foreach ($attaches as $key => $attach) {
                $attDelButtons .= '<input type="checkbox" name="' . $attach->getAttach_number()
                    . 'dattach"> ' . $attach->getAttach_number() . '. (' . $attach->getFilename() . ')' . "<br />\n";
            }
        }


		$markers->setCats_selector($cats_change);
        $markers->setAttaches_delete($attDelButtons);
        $markers->setMax_attaches($this->Register['Config']->read('max_attaches', $this->module));


		//navigation panel
		$navi = array();
		$navi['navigation'] = $this->_buildBreadCrumbs($entity->getCategory_id());
		$this->_globalize($navi);


        $source = $this->render('editform.html', array('context' => $markers));
		setReferer();
		return $this->_view($source);
	}



	/**
	 * 
	 * Validate data and update record into 
	 * Data Base. If an errors, redirect user to add form
	 * and show error message where speaks as not to admit 
	 * errors in the future
	 * 
	 */
	public function update($id = null)
    {
		$id = (int)$id;
		if ( $id < 1 ) redirect('/' . $this->module . '/');
        $errors = '';

        $target = $this->Model->getById($id);
        if (!$target) redirect('/' . $this->module . '/');


        //turn access
        if (!$this->ACL->turn(array($this->module, 'edit_materials'), false)
            && (!empty($_SESSION['user']['id']) && $target->getAuthor_id() == $_SESSION['user']['id']
                && $this->ACL->turn(array($this->module, 'edit_mine_materials'), false)) === false) {
            return $this->showInfoMessage(__('Permission denied'), '/' . $this->module . '/');
        }


        // Check additional fields if an exists.
        // This must be doing after define $error variable.
        if (is_object($this->AddFields)) {
            $_addFields = $this->AddFields->checkFields();
            if (is_string($_addFields)) $errors .= $_addFields;
        }

		$errors .= $this->Register['Validate']->check($this->getValidateRules());
		
		
		$valobj = $this->Register['Validate'];
		$fields = array('description', 'tags', 'sourse', 'sourse_email', 'sourse_site', 'download_url', 'download_url_size');
		$fields_settings = $this->Register['Config']->read('fields', $this->module);
		foreach ($fields as $field) {
			if (empty($_POST[$field]) && in_array($field, $fields_settings)) {
				$$field = null;
			} else {
				$$field = trim($_POST[$field]);
			}
		}
		
		
		// Обрезаем переменные до длины, указанной в параметре maxlength тега input
		$title  	= trim(mb_substr( $_POST['title'], 0, 128));
		$editLoad   = trim($_POST['mainText']);
		$in_cat 	= intval($_POST['cats_selector']);
		$commented = (!empty($_POST['commented'])) ? 1 : 0;
		$available = (!empty($_POST['available'])) ? 1 : 0;
		if (!$this->ACL->turn(array($this->module, 'record_comments_management'), false)) $commented = '1';
		if (!$this->ACL->turn(array($this->module, 'hide_material'), false)) $available = '1';


		// Preview
		if (isset($_POST['viewMessage'])) {
			$_SESSION['viewMessage'] = array_merge(array('title' => null, 'mainText' => null, 'in_cat' => $in_cat, 
				'description' => null, 'tags' => null, 'sourse' => null, 'sourse_email' => null, 
				'sourse_site' => null, 'download_url' => null, 'download_url_size' => null, 'commented' => null, 'available' => null), $_POST);
			redirect('/' . $this->module . '/edit_form/' . $id);
		}

		

		if (!empty($in_cat)) {
			$sectionsModel = $this->Register['ModManager']->getModelInstance($this->module . 'Sections');
			$category = $sectionsModel->getById($in_cat);
			if (!$category) $errors .= '<li>' . __('Can not find category') . '</li>' . "\n";
		}
		
		
		
		// Delete attached file if an exists and we get flag from editor
		if (!empty($_POST['delete_file']) || !empty($_FILES['attach_file']['name'])) {
			if ($target->getDownload() && file_exists($this->attached_files_path . $target->getDownload())) {
				_unlink($this->attached_files_path . $target->getDownload());
			}
		}
		

		// Errors
		if (!empty($errors)) {
			$_SESSION['FpsForm'] = array_merge(array('title' => null, 'mainText' => null, 
				'description' => null, 'tags' => null, 'sourse' => null, 'sourse_email' => null, 'in_cat' => $in_cat,
				'sourse_site' => null, 'download_url' => null, 'download_url_size' => null, 'commented' => null, 'available' => null), $_POST);
			$_SESSION['FpsForm']['error'] = '<p class="errorMsg">' . __('Some error in form') 
				. '</p>' . "\n" . '<ul class="errorMsg">'."\n".$errors.'</ul>'."\n";
			redirect('/' . $this->module . '/edit_form/' . $id );
		}
		
		
		//Проверяем прикрепленный файл...
		$file = '';
		if (!empty($_FILES['attach_file']['name'])) {
			$file = $this->__saveFile($_FILES['attach_file']);
		}
		
		
		downloadAttaches($this->module, $id);
		
		// Auto tags generation
		if (empty($tags)) {
			$TagGen = new MetaTags;
			$tags = $TagGen->getTags($editLoad);
			$tags = (!empty($tags) && is_array($tags)) ? implode(',', array_keys($tags)) : '';
		}
		
		
		$max_lenght = Config::read('max_lenght', $this->module);
		$editLoad = mb_substr($editLoad, 0, $max_lenght);
		// Запрос на обновление новости
		$data = array(
			'id' 		   => $id,
			'title' 	   => $title,
			'main' 		   => $editLoad,
			'category_id'  => $in_cat,
			'description'  => $description,
			'tags'         => $tags,
			'sourse'  	   => $sourse,
			'sourse_email' => $sourse_email,
			'sourse_site'  => $sourse_site,
			'download_url'  => $download_url,
			'download_url_size'  => $download_url_size,
			'commented'    => $commented,
			'available'    => $available,
		);
		if (!empty($file)) $data['download'] = $file;
        $target->__construct($data);
        $target->save();

		// Save additional fields if they is active
		if (is_object($this->AddFields)) {
			$this->AddFields->save($id, $_addFields);
		}
		
		
		//clear cache
		$this->Cache->clean(CACHE_MATCHING_TAG, array('record_id_' . $id, 'module_' . $this->module));
		$this->DB->cleanSqlCache();
		if ($this->Log) $this->Log->write('editing ' . $this->module, 'ent. id(' . $id . ')');
		
		return $this->showInfoMessage(__('Operation is successful'), getReferer());
	}



	// Функция удаляет тему; ID темы передается методом GET
	public function delete($id = null)
    {
		$this->cached = false;
		$id = (int)$id;
		if ($id < 1) redirect('/');


        $target = $this->Model->getById($id);
        if (!$target) redirect('/');


        //turn access
        if (!$this->ACL->turn(array($this->module, 'delete_materials'), false)
            && (!empty($_SESSION['user']['id']) && $target->getAuthor_id() == $_SESSION['user']['id']
                && $this->ACL->turn(array($this->module, 'delete_mine_materials'), false)) === false) {
            return $this->showInfoMessage(__('Permission denied'), '/' . $this->module . '/');
        }


        //remove cache
        $this->Cache->clean(CACHE_MATCHING_TAG, array('module_' . $this->module, 'record_id_' . $id));
        $this->DB->cleanSqlCache();

        $target->delete();

        $user_id = (!empty($_SESSION['user']['id'])) ? intval($_SESSION['user']['id']) : 0;
        if ($this->Log) $this->Log->write('delete ' . $this->module, 'ent. id(' . $id . ') user id('.$user_id.')');
        return $this->showInfoMessage(__('Operation is successful'), getReferer());
	}



    /**
     * add comment to stat
     *
     * @id (int)    stat ID
     * @return      info message
     */
    public function add_comment($id = null)
    {
        include_once(ROOT . '/sys/inc/includes/add_comment.php');
    }


	
    /**
     * add comment form to stat
     *
     * @id (int)    stat ID
     * @return      html form
     */
    private function _add_comment_form($id = null)
    {
        include_once(ROOT . '/sys/inc/includes/_add_comment_form.php');
        return $html;
    }



    /**
     * edit comment form to stat
     *
     * @id (int)    comment ID
     * @return      html form
     */
    public function edit_comment_form($id = null)
    {
        include_once(ROOT . '/sys/inc/includes/edit_comment_form.php');
    }



    /**
     * update comment
     *
     * @id (int)    comment ID
     * @return      info message
     */
    public function update_comment($id = null)
    {
        include_once(ROOT . '/sys/inc/includes/update_comment.php');
    }



    /**
     * get comments for stat
     *
     * @id (int)    stat ID
     * @return      html comments list
     */
    private function _get_comments($entity = null)
    {
        include_once(ROOT . '/sys/inc/includes/_get_comments.php');
        return $html;
    }



    /**
     * delete comment
     *
     * @id (int)    comment ID
     * @return      info message
     */
    public function delete_comment($id = null)
    {
        include_once(ROOT . '/sys/inc/includes/delete_comment.php');
    }



    /**
     * @param int $id - record ID
     *
     * update date by record also up record in recods list
     */
    public function upper($id)
    {
        //turn access
        $this->ACL->turn(array($this->module, 'up_materials'));
        $id = (int)$id;
        if ($id < 1) redirect('/' . $this->module . '/');


        $entity = $this->Model->getById($id);
        if (!$entity) redirect('/' . $this->module . '/');

        $entity->setDate(date("Y-m-d H-i-s"));
        $entity->save();
        return $this->showInfoMessage(__('Operation is successful'), '/' . $this->module . '/');
    }



    /**
     * @param int $id - record ID
     *
     * allow record be on home page
     */
    public function on_home($id)
    {
        //turn access
        $this->ACL->turn(array($this->module, 'on_home'));
        $id = (int)$id;
        if ($id < 1) redirect('/' . $this->module . '/');


        $entity = $this->Model->getById($id);
        if (!$entity) redirect('/' . $this->module . '/');

        $entity->setView_on_home('1');
        $entity->save();
        return $this->showInfoMessage(__('Operation is successful'), '/' . $this->module . '/');
    }



    /**
     * @param int $id - record ID
     *
     * denied record be on home page
     */
    public function off_home($id)
    {
        //turn access
        $this->ACL->turn(array($this->module, 'on_home'));
        $id = (int)$id;
        if ($id < 1) redirect('/' . $this->module . '/');


        $entity = $this->Model->getById($id);
        if (!$entity) redirect('/' . $this->module . '/');

        $entity->setView_on_home('0');
        $entity->save();
        return $this->showInfoMessage(__('Operation is successful'), '/' . $this->module . '/');
    }



    /**
     * @param int $id - record ID
     *
     * fix or unfix record on top on home page
     */
    public function fix_on_top($id)
    {
        $this->ACL->turn(array($this->module, 'on_home'));
        $id = (int)$id;
        if ($id < 1) redirect('/' . $this->module . '/');

        $target = $this->Model->getById($id);
        if (!$target) redirect('/');

        $curr_state = $target->getOn_home_top();
        $dest = ($curr_state) ? '0' : '1';
        $target->setOn_home_top($dest);
        $target->save();
        return $this->showInfoMessage(__('Operation is successful'), '/' . $this->module . '/');
    }



    public function download_file($id = null, $mimetype = 'application/octet-stream')
    {
		
		if (empty($id)) redirect('/');
		$id = intval($id);
		//clear cache
		$this->Cache->clean(CACHE_MATCHING_TAG, array('record_id_' . $id, 'module_load'));


        $entity = $this->Model->getById($id);
        if (!$entity) return $this->showInfoMessage(__('File not found'), '/' . $this->module . '/' );

        $entity->setDownloads($entity->getDownloads() + 1);
        $entity->save();
        $this->DB->cleanSqlCache();


        $name = $entity->getDownload();
        $filename = ROOT . '/sys/files/' . $this->module . '/' . $entity->getDownload();


        if (!file_exists($filename))
            return $this->showInfoMessage(__('File not found'), '/' . $this->module . '/' );
        $from = 0;
        $size = filesize($filename);
        $to = $size;


		if (isset($_SERVER['HTTP_RANGE'])) {
			if (preg_match('#bytes=-([0-9]*)#', $_SERVER['HTTP_RANGE'], $range)) {// если указан отрезок от конца файла
				$from = $size-$range[1];
				$to = $size;
			} elseif (preg_match('#bytes=([0-9]*)-#', $_SERVER['HTTP_RANGE'], $range)) {// если указана только начальная метка

				$from = $range[1];
				$to = $size;
			} elseif (preg_match('#bytes=([0-9]*)-([0-9]*)#', $_SERVER['HTTP_RANGE'], $range)) {// если указан отрезок файла

				$from = $range[1];
				$to = $range[2];
			}
			header('HTTP/1.1 206 Partial Content');

			$cr='Content-Range: bytes ' . $from .'-' . $to . '/' . $size;
		} else
			header('HTTP/1.1 200 Ok');
		
		$etag = md5($filename);
		$etag = substr($etag, 0, 8) . '-' . substr($etag, 8, 7) . '-' . substr($etag, 15, 8);
		header('ETag: "' . $etag . '"');
		header('Accept-Ranges: bytes');
		header('Content-Length: ' . ($to-$from));
		if (isset($cr))header($cr);
		header('Connection: close');
			
		header('Content-Type: ' . $mimetype);
		header('Last-Modified: ' . gmdate('r', filemtime($filename)));
		header("Last-Modified: ".gmdate("D, d M Y H:i:s", filemtime($filename))." GMT");
		header("Expires: ".gmdate("D, d M Y H:i:s", time() + 3600)." GMT");
		$f=fopen($filename, 'rb');


		if (preg_match('#^image/#',$mimetype))
			header('Content-Disposition: filename="' . $name . '";');
		else
			header('Content-Disposition: attachment; filename="' . $name . '";');

		fseek($f, $from, SEEK_SET);
		$size = $to;
		$downloaded = 0;
		while(!feof($f) and ($downloaded<$size)) {
			$block = min(1024*8, $size - $downloaded);
			echo fread($f, $block);
			$downloaded += $block;
			flush();
		}
		fclose($f);
	}


	
	public function download_file_url($id = null, $mimetype = 'application/octet-stream')
    {
	    $entity = $this->Model->getById($id);
        if (!$entity) return $this->showInfoMessage(__('File not found'), '/' . $this->module . '/' );

        $entity->setDownloads($entity->getDownloads() + 1);
        $entity->save();
		$this->DB->cleanSqlCache();
	
		header('Location: ' . $entity->getDownload_url());
	}
	

	
	/**
	* @param array $record - assoc record array
	* @return string - admin buttons
	*
	* create and return admin bar
	*/
	protected function _getAdminBar($record)
    {
		$moder_panel = '';
        $uid = $record->getAuthor_id();
        $id = $record->getId();


		if ($this->ACL->turn(array($this->module, 'edit_materials'), false) 
		|| (!empty($_SESSION['user']['id']) && $uid == $_SESSION['user']['id']
		&& $this->ACL->turn(array($this->module, 'edit_mine_materials'), false))) {
			$moder_panel .= get_link('', '/' . $this->module . '/edit_form/' . $id, array('class' => 'fps-edit')) . '&nbsp;';
		}
		
		if ($this->ACL->turn(array($this->module, 'up_materials'), false)) {
			$moder_panel .= get_link('', '/' . $this->module . '/fix_on_top/' . $id,
				array('class' => 'fps-star', 'onClick' => "return confirm('" . __('Are you sure') . "')")) . '&nbsp;';
			$moder_panel .= get_link('', '/' . $this->module . '/upper/' . $id,
				array('class' => 'fps-up', 'onClick' => "return confirm('" . __('Are you sure') . "')")) . '&nbsp;';
		}
		
		if ($this->ACL->turn(array($this->module, 'on_home'), false)) {
				if ($record->getView_on_home() == 1) {
					$moder_panel .= get_link('', '/' . $this->module . '/off_home/' . $id, array(
						'class' => 'fps-on', 
						'onClick' => "return confirm('" . __('Are you sure') . "')",
					)) . '&nbsp;';
				} else {
					$moder_panel .= get_link('', '/' . $this->module . '/on_home/' . $id, array(
						'class' => 'fps-off',
						'onClick' => "return confirm('" . __('Are you sure') . "')",
					)) . '&nbsp;';
				}
		}
		
		if ($this->ACL->turn(array($this->module, 'delete_materials'), false) 
		|| (!empty($_SESSION['user']['id']) && $uid == $_SESSION['user']['id']
		&& $this->ACL->turn(array($this->module, 'delete_mine_materials'), false))) {
			$moder_panel .= get_link('', '/' . $this->module . '/delete/' . $id,
				array('class' => 'fps-delete', 'onClick' => "return confirm('" . __('Are you sure') . "')")) . '&nbsp;';
		}
		
		return $moder_panel;
	}
	

	
	/**
	 * Try Save file
	 * 
	 * @param array $file (From POST request)
	 */
	private function __saveFile($file)
    {
		// Массив недопустимых расширений файла вложения
		$extentions = $this->denyExtentions;
		// Извлекаем из имени файла расширение
		$ext = strrchr( $file['name'], "." );
		
		
		// Формируем путь к файлу
		if (in_array(strtolower($ext), $extentions)) {
			$path = date("YmdHis", time()) . '.txt';
		} else {
			$path = date("YmdHis", time()) . $ext;
		}
		
		
		$files_dir = ROOT . '/sys/files/' . $this->module . '/';
		$path = getSecureFilename($file['name'] . '_' . $path, $files_dir);
		
		
		// Перемещаем файл из временной директории сервера в директорию files
		if (move_uploaded_file($file['tmp_name'], ROOT . '/sys/files/' . $this->module . '/' . $path)) {
			chmod( ROOT . '/sys/files/' . $this->module . '/' . $path, 0644 );
		}
		
		return $path;
	}
	


    /**
     * RSS
	 *
     */
    public function rss()
    {
		include_once ROOT . '/sys/inc/includes/rss.php';
    }
	
	
	
	public function set_rating($id = null)
    {
		include_once(ROOT . '/sys/inc/includes/set_rating.php');
	}
	
	
	
	public function getValidateRules() 
	{
		$max_attach = $this->Register['Config']->read('max_attaches', $this->module);
		if (empty($max_attach) || !is_numeric($max_attach)) $max_attach = 5;
		$rules = array(
			'add' => array(
				'title' => array(
					'required' => true,
					'max_lenght' => 250,
					'title' => 'Title',
				),
				'mainText' => array(
					'required' => true,
					'max_lenght' => $this->Register['Config']->read('max_lenght', $this->module),
					'title' => 'Text',
				),
				'cats_selector' => array(
					'required' => true,
					'pattern' => V_INT,
					'max_lenght' => 11,
					'title' => 'Category',
				),
				'description' => array(
					'required' => 'editable',
				),
				'tags' => array(
					'required' => 'editable',
					'pattern' => V_TITLE,
				),
				'sourse' => array(
					'required' => 'editable',
					'pattern' => V_TITLE,
				),
				'sourse_email' => array(
					'required' => 'editable',
					'pattern' => V_MAIL,
				),
				'sourse_site' => array(
					'required' => 'editable',
					'pattern' => V_URL,
				),
				'download_url' => array(
					'required' => 'editable',
					'pattern' => V_URL,
				),
				'download_url_size' => array(
					'required' => 'editable',
					'pattern' => V_INT,
				),
				'files__attach' => array(
					'for' => array(
						'from' => 1,
						'to' => $max_attach,
					),
					'type' => 'image',
					'max_size' => Config::read('max_attaches_size', $this->module),
				),
				'files__attach_file' => array(
					'type' => 'file',
					'max_size' => Config::read('max_file_size', $this->module),
					'required' => 'editable',
					'required_error' => __('Not attachment'),
				),
				'commented' => array(),
				'available' => array(),
			),
			'update' => array(
				'title' => array(
					'required' => true,
					'max_lenght' => 250,
					'title' => 'Title',
				),
				'mainText' => array(
					'required' => true,
					'max_lenght' => Config::read('max_lenght', $this->module),
					'title' => 'Text',
				),
				'cats_selector' => array(
					'required' => true,
					'pattern' => V_INT,
					'max_lenght' => 11,
					'title' => 'Category',
				),
				'description' => array(
					'required' => 'editable',
				),
				'tags' => array(
					'required' => 'editable',
					'pattern' => V_TITLE,
				),
				'sourse' => array(
					'required' => 'editable',
					'pattern' => V_TITLE,
				),
				'sourse_email' => array(
					'required' => 'editable',
					'pattern' => V_MAIL,
				),
				'sourse_site' => array(
					'required' => 'editable',
					'pattern' => V_URL,
				),
				'download_url' => array(
					'required' => 'editable',
					'pattern' => V_URL,
				),
				'download_url_size' => array(
					'required' => 'editable',
					'pattern' => V_INT,
				),
				'files__attach' => array(
					'for' => array(
						'from' => 1,
						'to' => $max_attach,
					),
					'type' => 'image',
					'max_size' => Config::read('max_attaches_size', $this->module),
				),
				'files__attach_file' => array(
					'type' => 'file',
					'max_size' => Config::read('max_file_size', $this->module),
					'required' => false,
				), 				
				'commented' => array(),
				'available' => array(),
			),
			'add_comment' => array(
				'login' => array(
					'required' => true,
					'pattern' => V_TITLE,
					'max_lenght' => 40,
				),
				'message' => array(
					'required' => true,
				),
				'captcha_keystring' => array(
					'pattern' => V_CAPTCHA,
					'title' => 'Kaptcha',
				),
			),
			'update_comment' => array(
				'login' => array(
					'required' => true,
					'pattern' => V_TITLE,
					'max_lenght' => 40,
				),
				'message' => array(
					'required' => true,
				),
				'captcha_keystring' => array(
					'pattern' => V_CAPTCHA,
					'title' => 'Kaptcha',
				),
			),
		);
		
		return array($this->module => $rules);
	}
}


