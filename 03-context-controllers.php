<?php
/**
 * ============================================================================
 * ПРИМЕР 3. Контекст запроса (филиал / год приёма) и переиспользуемый CRUD
 * ----------------------------------------------------------------------------
 * Источники: protected/components/FilialProfileController.php,
 *            protected/components/YearProfileController.php,
 *            protected/components/actions/abit/create.php
 *
 * Почти каждый экран системы имеет три измерения: филиал (арендатор), год
 * приёмной кампании и — для карточек — конкретный абитуриент. Потерять любое
 * из них значит показать чужие данные или записать абитуриента не в тот год.
 *
 *  1. Цепочка базовых контроллеров Controller -> FilialProfileController ->
 *     YearProfileController -> AbitProfileController. Каждый уровень добавляет
 *     свой фильтр контекста и свои правила доступа, причём accessRules
 *     собираются через array_merge(parent::accessRules(), ...).
 *  2. Контекст резолвится один раз в фильтре, до экшена, и даёт 404, если
 *     сущность не найдена.
 *  3. Переопределён createUrl(): fid и yid подставляются во все ссылки, и
 *     контекст не теряется при навигации.
 *  4. Год НЕ хранится в сессии — см. комментарий в filterYearContext().
 *  5. Экшен-класс create — часть переиспользуемого CRUD для ~20 однотипных
 *     сущностей-спутников абитуриента (работа, языки, награды, родители...).
 *     Проверка прав живёт внутри экшена, поэтому её нельзя забыть.
 * ============================================================================
 */


/* ============================================================
 * Файл: protected/components/FilialProfileController.php
 * ============================================================ */

/**
 * ProfileController - это переопределенный и кастомизированный класс Controller
 * (который в свою очередь кастомизирует базовый класс).
 */
class FilialProfileController extends Controller
{
	/**
	 * @var GupFilial публичное свойство, содержащее экземляр связанной модели Filial
	 */
	public $boundFilial = null;
	
	public function accessRules()
	{
		return array_merge(parent::accessRules(), array(
			array('allow',
				'actions'=>array('filial.switch'), // действие, переключающее филиал
				'roles'=>array('pricom'=>array('filial'=>$this->boundFilial)),
			),
		));
	}
	
	/**
	 * Набор действий
	 * @see CController::actions()
	 */
	public function actions()
	{
		return array(
			// filial. - это префикс, который нужно использовать в URL
			// для всех действий, находящихся внутри виджета FilialSwitcher,
			// при обращении к ним на страницах, где размещается виджет.
			'filial.'=>'application.components.FilialSwitcherWidget', // действия из виджета "Переключатель филиала"
		);
	}

	//////// Проверка того, что филиал выбран и существует, перед добавлением связанного элемента ////////

	/**
	 * приватный метод для установки связанной модели Абитуриента
	 * @param int идентификатор связанного абитуриента
	 * @throws CHttpException
	 * @return экземпляр модели класса Filial, основанный на переданном идентификаторе
	 */
	protected function loadFilial($filial_id)
	{
		// Если свойство $boundFilial == null, создать его, основываясь на $filial_id
		if ($this->boundFilial === null)
		{
			if ($filial_id !== null)
			{
				$this->boundFilial = GupFilial::model()->findByPk($filial_id);
				if ($this->boundFilial === null)
				{
					throw new CHttpException(404, Yii::t('filial', 'The requested filial does not exist.'));
				}
			}
			else
			{
				throw new CHttpException(404, Yii::t('filial', 'You must create filial first.'));
			}
		}
		return $this->filial;
	}

	/**
	 * Метод фильтрации, запускаемый из метода filter()
	 * Вызывается перед методом actionCreate() и выполняет проверку,
	 * что филиал выбран перед созданием связанных с ним записей
	 * @param unknown_type $filterChain
	 */

	public function filterFilialContext($filterChain)
	{
		// установка filial_id, на основе параметров, переданных через GET или POST
		$filialId = null;

		if (isset($_GET['fid']))
		{
			$filialId = $_GET['fid'];
		}
		else
			if (isset($_POST['fid']))
			{
				$filialId = $_POST['fid'];
			}
		else {
			// по умолчанию основной вуз
			$filialId = 0;
		}
		$this->loadFilial($filialId);

		// запуск остальных фильтров
		$filterChain->run();
	}

	/**
	 * Возвращает экземляр модели Filial, которой принадлежит раздел профиля.
	 */
	public function getFilial()
	{
		return $this->boundFilial;
	}

	/**
	 * Возвращает id модели Filial, которой принадлежит раздел профиля.
	 */
	public function getFilialId()
	{
		return $this->boundFilial->id;
	}
	
	public function createUrl($route,$params=array(),$ampersand='&')
	{
		if (!isset($params['fid']) )
		{
			// добавить параметр 'fid' (филиал) для каждого url, создаваемого yii
			$params['fid']= (!is_null($this->boundFilial) && !is_null($this->boundFilial->id)) ? $this->boundFilial->id : 0;
		}
		return parent::createUrl($route, $params, $ampersand);
	}
}
/* ============================================================
 * Файл: protected/components/YearProfileController.php
 * ============================================================ */

/**
 * ProfileController - это переопределенный и кастомизированный класс Controller
 */
class YearProfileController extends FilialProfileController
{
	/**
	 * @var защищенное свойство, содержащее экземляр связанной модели Year
	 */
	public $boundYear = null;
	
	/**
	 * @var приватное свойство, содержащее год набора
	 */
	private $_naborYear = null;
	
	
	public function init() {
		$this->_naborYear = GupHelper::NaborYear();
	}

	public function accessRules()
	{
		return array_merge(parent::accessRules(), array(
			array(
				'allow',
				'actions' => array('year.switch'), // действие, переключающее год
				'roles' => array('pricom' => array('filial' => $this->boundFilial)),
			),
		));
	}

	/**
	 * Набор действий
	 * @see CController::actions()
	 */
	public function actions()
	{
		return array_merge(parent::actions(), array(
				// year. - это префикс, который нужно использовать в URL
				// для всех действий, находящихся внутри виджета YearSwitcher,
				// при обращении к ним на страницах, где размещается виджет.
				'year.'=>'application.components.YearSwitcherWidget', // действия из виджета "Переключатель года"
		));
	}
	
	//////// Проверка того, что год выбран и существует, перед добавлением/изменением/запросом связанного элемента ////////

	/**
	 * приватный метод для установки связанной модели Year
	 * @param int идентификатор связанной модели Year
	 * @throws CHttpException
	 * @return экземпляр модели класса Year, основанный на переданном идентификаторе
	 */
	protected function loadYear($year_id)
	{
		// Если свойство $boundYear == null, создать его, основываясь на $year_id
		if ($this->boundYear === null)
		{
			if ($year_id != null)
			{
				$this->boundYear = NaborYear::model()->findByPk($year_id);
				if ($this->boundYear === null)
				{
					throw new CHttpException(404, Yii::t('year', 'The requested year does not exist.'));
				}
			}
			else
			{
				throw new CHttpException(404, Yii::t('year', 'You must select year first.'));
			}
		}
		return $this->year;
	}

	/**
	 * Метод фильтрации, запускаемый из метода filter()
	 * Вызывается перед методом actionCreate() и выполняет проверку,
	 * что год выбран перед выполнением нужного действия
	 * @param unknown_type $filterChain
	 */

	public function filterYearContext($filterChain)
	{
		// установка yearId, на основе параметров, переданных через GET или POST
		$yearId = null;

		if (isset($_GET['yid']))
		{
			$yearId = $_GET['yid'];
		}
		// отключил хранение года в сессии, т.к. могут возникнуть глюки - год часто используется при поиске и создании элементов.
		// если открыто несколько страниц, в них может содержаться разные года, но при сохранении на сервер будет определен последний год.
		/*else if (isset(Yii::app()->session['yid']) && (Yii::app()->session['yid']!=''))
		{
			$yearId = Yii::app()->session['yid'];
		}*/
		else {
			// по умолчанию работаем с текущим годом набора (рассчитывается)
			$yearId = $this->_naborYear;
		}
			
		$this->loadYear((int)$yearId);

		// запуск остальных фильтров
		$filterChain->run();
	}

	/**
	 * Возвращает экземляр модели Year
	 */
	public function getYear()
	{
		return $this->boundYear;
	}

	/**
	 * Возвращает id модели Year
	 */
	public function getYearId()
	{
		return $this->boundYear->id;
	}
	

	public function createUrl($route,$params=array(),$ampersand='&')
	{
		if (!isset($params['yid']) )
		{
			// добавить параметр 'yid' (год) для каждого url, создаваемого yii
			$params['yid']=!empty($this->boundYear->id) ? $this->boundYear->id : $this->_naborYear;
		}
		return parent::createUrl($route, $params, $ampersand);
	}
}
/* ============================================================
 * Файл: protected/components/actions/abit/create.php
 * ============================================================ */


/**
 * Creates a new model.
 * If creation is successful, the browser will be redirected to the 'index' page.
 */
class create extends CAction
{

	/**
	 *
	 * @var string ModelName
	 */
	public $model_name;

	public function run()
	{
		$model_class = $this->model_name;
		
		$controller = $this->getController();
		
		$model = new $model_class();
		$model->abit_id = $controller->boundAbit->id;
		
		if (! Yii::app()->user->checkAccess('AbitRelated.Create', array(
			'abit' => $controller->boundAbit,
		))) {
			throw new CHttpException(403, Yii::t('default', 'You are not authorized to perform this action.'));
		}
		
		// Uncomment the following line if AJAX validation is needed
		// $this->performAjaxValidation($model);
		
		if (isset($_POST[$model_class])) {
			$model->attributes = $_POST[$model_class];
			$model->abit_id = $controller->boundAbit->id;
			if ($model->save()) {	
				$url = ! empty($controller->returnUrl) ? $controller->returnUrl : 'index';
				$controller->redirect(array(
					$url,
					'eid' => $controller->boundAbit->id
				));
			}
		}
		
		$controller->render('create', array(
			'model' => $model
		));
	}
}