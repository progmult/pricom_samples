<?php
/**
 * ============================================================================
 * ПРИМЕР 4. Основной контроллер: доступ, реестр с выгрузкой, переходы статусов
 * ----------------------------------------------------------------------------
 * Источник: protected/controllers/AbitController.php (1939 строк, здесь —
 * правила доступа, реестр заявлений и два перехода жизненного цикла).
 *
 *  1. Двухступенчатая защита. Грубая — accessRules по экшенам и ролям, причём
 *     'roles' => array('pricom' => array('filial' => $this->boundFilial)):
 *     второй элемент уходит в bizRule назначения роли (пример 02), поэтому
 *     проверка "оператор ли ты" превращается в "оператор ли ты в этом филиале".
 *     Точная — checkAccess внутри экшена с конкретной моделью.
 *  2. Все изменяющие действия только POST-ом.
 *  3. Бизнес-логика перехода живёт в модели (пример 05), контроллер отвечает
 *     за права, транспорт, уведомление, сброс кеша и редирект.
 *  4. Выгрузка реестра в CSV использует тот же dataProvider, что и грид,
 *     поэтому выгружается ровно то, что видит пользователь после фильтрации.
 *     Пятый аргумент exportCSV — колонки, которые надо отдать текстом, иначе
 *     Excel превращает номер телефона в 8,9211E+10.
 * ============================================================================
 */


class AbitController extends YearProfileController
{
	/**
	 * @var string the default layout for the views. Defaults to '//layouts/column2', meaning
	 * using two-column layout. See 'protected/views/layouts/column2.php'.
	 */
	public $layout='//layouts/column2';

	/**
	 * @return array action filters
	 */
	public function filters()
	{
		return array(
				'yearContext + admin review region reject approve verify jNote jReferral transfer assignSchool withdraw stay stok stokCancel amend verifyAmended potential calculateBalls transfer year.switch filial.switch printFile history updateSmall', // проверка контекста учебного года
				'filialContext + admin review region reject approve verify jNote jReferral transfer assignSchool withdraw stay stok stokCancel amend verifyAmended potential calculateBalls transfer year.switch filial.switch print printFile rtfCard rtfAgree history hide show updateSmall pricomSubmit', // проверка контекста филиала
				'accessControl', // perform access control for CRUD operations
		);
	}

	/**
	 * Specifies the access control rules.
	 * This method is used by the 'accessControl' filter.
	 * @return array access control rules
	 */
	public function accessRules()
	{
		return array_merge(parent::accessRules(), array(
				array('allow', // allow authenticated user to perform some actions
						'actions'=>array('update','updateSkills','updateMisc', 'submit'),
						'users'=>array('@'),
				),
				array('allow', // allow users with role "pricom" to perform some actions
						'actions'=>array('admin', 'review', 'print', 'transfer', 'potential', 'reject', 'approve', 'verify', 'region', 'printfile', 'rtfCard', 'rtfAgree', 'index', 'amend', 'verifyAmended', 'stok', 'stokCancel'),
						'roles'=>array('pricom'=>array('filial'=>$this->boundFilial)),
				),
				array('allow', // allow users with role "pricom-dir" to perform some actions
						'actions'=>array('jReferral','jNote','transfer', 'potential', 'calculateBalls', 'assignSchool','withdraw', 'stay', 'history', 'hide', 'show', 'updateSmall', 'pricomSubmit'),
						'roles'=>array('pricom-dir'=>array('filial'=>$this->boundFilial)),
				),
				array('deny',  // deny all users
						'users'=>array('*'),
				),
		));
	}
	
	public function behaviors() {
		return array(
			'exportableGrid' => array(
				'class' => 'application.components.behaviors.ExportableGridBehavior',
				'filename' => 'Список абитуриентов '.date('Y-m-d').'.csv',
				'csvDelimiter' => ';', //i.e. Excel friendly csv delimiter
			));
	}

	/* ----- сокращено: actionReview, actionCreate, actionUpdate, actionDelete, actionIndex ----- */

	/**
	 * Управление абитуриентами (просмотр списка, фильтрация).
	 */
	public function actionAdmin()
	{
		$this->layout = 'column1Admin';
		$nabor_year = $this->boundYear->id;
		$model=new Abit('search');

		if (!isset($_GET['Abit']['learn_year']))
			$model->learn_year = $nabor_year;
		
		if (!isset($_GET['Abit']['is_hidden']))
			$model->is_hidden = 0;

		if (isset($_GET['abitPageSize'])) {
			Yii::app()->user->setState('abitPageSize',(int)$_GET['abitPageSize']);
			unset($_GET['abitPageSize']);
		}
		
		$model->filial_id = $this->boundFilial->id;
		
		
		if ($this->isExportRequest()) { //<==== [[ADD THIS BLOCK BEFORE RENDER]]
			//set_time_limit(0); //Uncomment to export lage datasets
			//Add to the csv a single line of text
			// $this->exportCSV(array('POSTS WITH FILTER:'), null, false);
			//Add to the csv a single model data with 3 empty rows after the data
			//$this->exportCSV($model, array_keys($model->attributeLabels()), false, 3);
			//Add to the csv a lot of models from a CDataProvider
			$this->exportCSV(
				$model->search(),
				array('id','create_time','update_time','fullname','selectedSpecOptions','selectedLearnFormOptions','abitContact.mobile_phone','userCreated.email','cur_city','submit_time','typeStatus.name'),
				true, 0, array('abitContact.mobile_phone')
			);
		}
		
		$this->render('admin',array(
			'model'=>$model,
			'nabor_year'=>$nabor_year,
		));
	}

	/* ----- сокращено: actionHistory ----- */

	/**
	 * Берет заявление на проверку.
	 * После этого проиходит перенаправление на страницу просмотра.
	 */
	public function actionVerify($id)
	{
		if(Yii::app()->request->isPostRequest)
		{
			// разрешается только методом POST

			// проверка безопасности
			$model = $this->loadModel($id);
			if(!Yii::app()->user->checkAccess('Abit.Admin', array('abit'=>$model,'filial'=>$this->boundFilial)))
			{
				throw new CHttpException(403,Yii::t('default','You are not authorized to perform this action.'));
			}

			if ($model->verifyApplication())
			{
				Yii::app()->user->setFlash('success',Yii::t('abit', 'Application taked for review.'));
				// отправить письмо абитуриенту
				$notifier = new Notifier();
				$notifier->mailVerify($model);
			}
			else {
				Yii::app()->user->setFlash('error',Yii::t('abit', 'Application was not taked for review.'));
			}

			// если это AJAX запрос, редиректить не надо
			if(!isset($_GET['ajax']))
				$this->redirect(isset($_POST['returnUrl']) ? $_POST['returnUrl'] : array('review','id'=>$id));
		}
		else
			throw new CHttpException(400,'Invalid request. Please do not repeat this request again.');

	}

	/* ----- сокращено: actionVerifyAmended ----- */

	/**
	 * Отклоняет заявление.
	 * После отклонения происходит редирект на страницу "admin"
	 */
	public function actionReject($id)
	{
		if(Yii::app()->request->isPostRequest)
		{
			// разрешается отклонение только методом POST

			// проверка безопасности
			$model = $this->loadModel($id);
			if(!Yii::app()->user->checkAccess('Abit.Admin', array('abit'=>$model,'filial'=>$this->boundFilial)))
			{
				throw new CHttpException(403,Yii::t('default','You are not authorized to perform this action.'));
			}

			if ($model->rejectApplication($_POST['reject_comment'],$_POST['Abit']['type_reject_id']))
			{
				Yii::app()->user->setFlash('success',Yii::t(
					'abit', 'Application #{id} {link} rejected.', array(
						'{id}'=>$id,
						'{link}'=>CHtml::link(CHtml::encode($model->fullname),array('review','id'=>$id))
				)));
				
				// удалить кэш для страниц планов набора, куда абитуриент подал заявления
				$this->deleteCache(GupHelper::getNaborCacheIdByAbit($model->id));
				
				// отправить письмо абитуриенту
				$notifier = new Notifier();
				$notifier->mailReject($model);
			} else {
				Yii::app()->user->setFlash('error',Yii::t('abit', 'Can`t reject application.'));
			}

			// если это AJAX запрос, редиректить не надо
			if(!isset($_GET['ajax']))
				$this->redirect(isset($_POST['returnUrl']) ? $_POST['returnUrl'] : array('admin'));
		}
		else
			throw new CHttpException(400,'Invalid request. Please do not repeat this request again.');
	}

	/* ----- сокращено: actionApprove, actionWithdraw, actionStay, actionStok, actionAmend, actionHide, печатные формы и loadModel ----- */

}
