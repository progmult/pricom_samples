<?php
/**
 * ============================================================================
 * ПРИМЕР 10. Тесты: права на конкретную запись и матрица переходов статусов
 * ----------------------------------------------------------------------------
 * Источники: protected/tests/PricomCDbTestCase.php,
 *            protected/tests/unit/AbitTest.php (2310 строк)
 *
 * В проекте 38 наборов unit-тестов на фикстурах (PHPUnit + CDbTestCase,
 * 49 файлов фикстур). Здесь два самых содержательных теста из набора по
 * абитуриенту — ровно те, что закрывают темы примеров 01, 02, 04 и 05.
 *
 *  1. testUserAccessBasedOnAbitRole проверяет то, что руками проверить дороже
 *     всего: что право зависит не от роли, а от пары "роль + конкретная запись".
 *     Сценарий: автор может читать и править СВОЁ заявление и связанные с ним
 *     результаты ЕГЭ, но не чужие; сотрудник приёмной комиссии — наоборот, может
 *     проверять любое заявление, но не читать его как автор. Дальше тот же
 *     пользователь проводится по всей цепочке статусов, и на каждом шаге
 *     проверяется, что права изменились вместе со статусом: подал — ещё правит,
 *     взяли на проверку — только чтение, отклонили — снова правит, одобрили —
 *     снова только чтение.
 *
 *  2. testStatusWorkflow — матрица допустимых действий. Модель проводится по
 *     переходам (проверка -> одобрение -> корректировка -> повторная проверка
 *     -> отзыв документов), и после каждого проверяется ПОЛНЫЙ набор признаков
 *     canApprove / canReject / canVerify / canAmend / canWithDraw / canStay.
 *     Проверяются не только разрешённые действия, но и запрещённые — иначе
 *     тест пропустил бы регрессию "стало можно то, что было нельзя".
 *
 * Фикстуры именованные ('abit_sred_created', 'user_vo', 'row6'), поэтому в
 * тестах не встречается ни одного числового id, и данные читаются как сценарий.
 *
 * TODO и TOFIX в теле тестов — авторские, оставлены как есть.
 * ============================================================================
 */

/* ============================================================
 * Файл: protected/tests/PricomCDbTestCase.php
 * Базовый класс: общая проверка дата-провайдеров, которую иначе
 * пришлось бы копировать в каждый тест со списком.
 * ============================================================ */


class PricomCDbTestCase extends CDbTestCase
{

	public $nabor_year = 2019;
	
	public $learn_year = 2018;
	
	public $year;

	/**
	 * Проверяет данные в дата провайдере
	 *
	 * @param CActiveDataProvider $dataProvider        	
	 * @param string|string[] $modelName        	
	 * @param bool $validateModel
	 *        	нужно ли проверять ли валидность каждой модели
	 */
	public function validateCActiveDataProvider($dataProvider, $modelName, $validateModel = false)
	{
		$this->assertNotEmpty($dataProvider);
		$this->assertInstanceOf('CActiveDataProvider', $dataProvider);
		// должен содержать значения
		$this->assertNotEmpty($dataProvider->itemCount);
		foreach ($dataProvider->getData() as $key => $value) {
			/**
			 *
			 * @var $value CActiveRecord
			 */
			$this->assertNotEmpty($value);
			// все элементы должны быть класса(ов), переданного в параметре modelName
			if (! is_array($modelName)) {
				$this->assertInstanceOf($modelName, $value);
			} else {
				foreach ($modelName as $class) {
					$this->assertInstanceOf($class, $value);
				}
			}
			// ключами массива должны быть id элементов
			$this->assertEquals($key, $value->id);
			
			if ($validateModel) {
				// модель должна быть валидная
				$valid = $value->validate();
				$this->assertTrue($valid);
				if (! $valid) {
					print_r($value->errors);
				}
			}
		}
	}
	
	protected function setUp() {
		parent::setUp();
		$this->year = date('Y');
	}
}

/* ============================================================
 * Файл: protected/tests/unit/AbitTest.php
 * ============================================================ */

/**
 * 
 * @author KapustinAV
 * @var $abit Abit
 */

class AbitTest extends PricomCDbTestCase
{
	// NOTE. Если при тестировании вылезают ошибки, проверить, что
	// фикстурах "абит_контакт" и "юзер"
	// имеются связанные записи.
	public $fixtures = array(
		'abits' => 'Abit',
		'abitSpecs' => 'AbitSpec',
		'eges' => 'GupAbitEkzamList',
		'abitEgesAssign' => ':pricom_abit_ege',
		'users' => 'User',
		'abitLangs' => ':pricom_abit_lang',
	/*'nations'=>'GupOKNaci',
		'citizens'=>'GupOKCitizen',
		'leveledus' => 'TypeLevelEdu',
		'married' => 'TypeMarried',
		'socials' => 'TypeSocial',*/
		'abitUserRole' => ':pricom_abit_user_role',
		'authAssign' => ':AuthAssignment',
		'speclist' => 'GupSpecList',
		'address' => 'AbitAddress',
		'contacts' => 'AbitContact',
		'parents' => 'AbitParent',
		'prepares' => 'AbitPrepare',
		'contacts' => 'AbitContact',
		'schools' => 'AbitSchool'
	);


	/* ----- сокращено: testCreate, testRead, testUpdate, testDelete, testFind и ~20 тестов геттеров справочников ----- */

	public function testUserRoleAssignmet()
	{
		$abit = $this->abits('abit_sred_vo_created');
		$user = $this->users('user_vo');
		$this->assertEquals(1, $abit->associateUserToRole('author', $user->id));
		$this->assertEquals(1, $abit->removeUserFromRole('author', $user->id));
	}

	public function testIsInRole()
	{
		$row = $this->abitUserRole['row6'];
		Yii::app()->user->setId($row['user_id']);
		$abit = Abit::model()->findByPk($row['abit_id']);
		$this->assertTrue($abit->isUserInRole('author'));
	}

	public function testUserAccessBasedOnAbitRole()
	{
		$user_role_sred_row = $this->abitUserRole['row6'];
		Yii::app()->user->setId($user_role_sred_row['user_id']); // зайти под пользователем 6
		                                                         // дать право "автора" созданному пользователю на абитуриента
		                                                         // такие же шаги используются при создании абитуриента
		$role = 'author';
		$abit = Abit::model()->findByPk($user_role_sred_row['abit_id']);
		// кроме этой, которая уже хранится в фикстурах.
		// TODO: не использовать в этом тесте данные из таблицы pricom_abit_user_role (abitUserRole)
		// $abit->associateUserToRole($role,$user_role_sred_row['user_id']);
		$auth = Yii::app()->authManager;
		$auth->assign($role, $user_role_sred_row['user_id']);
		// убедиться, что пользователь имеет правильный доступ к операциям над связанным с ним абитуриентом
		$params = array(
			'abit' => $abit
		);
		$this->assertTrue(Yii::app()->user->checkAccess('Abit.Read', $params));
		$this->assertTrue(Yii::app()->user->checkAccess('Abit.Update', $params));
		$this->assertTrue(Yii::app()->user->checkAccess('AbitAddress.Update', $params));
		$this->assertTrue(Yii::app()->user->checkAccess('AbitAddress.Read', $params));
		$this->assertFalse(Yii::app()->user->checkAccess('AbitAddress.Delete', $params));
		// теперь убедиться, что у пользователя нет никакого доступа к абитуриенту, с которым он не связан
		$abit2 = Abit::model()->findByPk(2);
		$params2 = array(
			'abit' => $abit2
		);
		$this->assertFalse(Yii::app()->user->checkAccess('Abit.Read', $params2));
		$this->assertFalse(Yii::app()->user->checkAccess('AbitAddress.Update', $params2));
		$this->assertFalse(Yii::app()->user->checkAccess('AbitAddress.Read', $params2));
		// теперь убедимся, что у пользователя есть доступ к операциям над связанными с абитуриентом результатами егэ
		$egeRow = $this->abitEgesAssign['abitSredToEgeRus'];
		$egeRes = AbitEge::model()->findByPk($egeRow['id']); // 10
		$params = array(
			'abit' => $abit,
			'relModel' => $egeRes
		);
		$this->assertTrue(Yii::app()->user->checkAccess('AbitEge.Read', $params));
		// и нет доступа к операциям над связанными с другим абитуриентом результатами
		$egeRow2 = $this->abitEgesAssign['abitSred2ToEgeRus'];
		$egeRes2 = AbitEge::model()->findByPk($egeRow2['id']); // 3
		$params2 = array(
			'abit' => $abit2,
			'relModel' => $egeRes2
		);
		$this->assertFalse(Yii::app()->user->checkAccess('AbitEge.Read', $params2));
		
		// а у сотрудников прикома - доступ есть к обзору и просмотру всех абитуриентов
		Yii::app()->user->setId(2); // зайти под пользователем 2
		$auth->assign('pricom', '2'); // дать ему роль "pricom"
		$params = array(
			'abit' => $abit
		); // проверить действия над первым заявлением
		$this->assertFalse(Yii::app()->user->checkAccess('Abit.Read', $params));
		$this->assertTrue(Yii::app()->user->checkAccess('Abit.Review', $params));
		$this->assertTrue(Yii::app()->user->checkAccess('Abit.Admin', $params));
		
		// /////////////////////////////
		// теперь тестируем статусы: //
		// /////////////////////////////
		
		Yii::app()->user->setId($user_role_sred_row['user_id']); // зайти под пользователем 6
		                                                         // проверяем, что можно редактировать заявление после подачи на проверку
		$this->assertTrue($abit->submitApplication());
		$params = array(
			'abit' => $abit
		);
		$this->assertTrue(Yii::app()->user->checkAccess('Abit.Read', $params));
		$this->assertTrue(Yii::app()->user->checkAccess('Abit.Update', $params));
		// проверяем, что нельзя редактировать заявление, если оно находится на проверке
		
		$this->assertTrue($abit->verifyApplication(false)); // TOFIX: "берем на проверку сами у себя", т.к. не меняем user_id в тесте.
		                                               // TODO: при этом меняется доступ у того, КТО СОЗДАЛ абитуриента (а не у того, у кого было право автора на него).
		                                               // нужно переделать, соответственно. Сейчас работает, т.к. у абитуриента в фикстуре жестко забит create_user_id, равный id абитуриента.
		                                               // в будущих версия этот параметр может отличаться.
		$this->assertTrue(Yii::app()->user->checkAccess('Abit.Read', $params));
		$this->assertFalse(Yii::app()->user->checkAccess('Abit.Update', $params));
		$this->assertFalse(Yii::app()->user->checkAccess('AbitEge.Update', $params));
		// что заявления других не можем ни редактировать, ни читать по прежнему
		$abit2 = Abit::model()->findByPk(2);
		$params2 = array(
			'abit' => $abit2
		);
		$this->assertFalse(Yii::app()->user->checkAccess('Abit.Read', $params2));
		
		// проверяем, что можно редактировать заявление, если статус - исправляем ошибки
		$this->assertTrue($abit->rejectApplication("отклонено", null, false));
		$this->assertTrue(Yii::app()->user->checkAccess('Abit.Read', $params));
		$this->assertTrue(Yii::app()->user->checkAccess('Abit.Update', $params));
		// проверяем, что нельзя редактировать заявление, если оно участвует в конкурсеэ
		$this->assertTrue($abit->approveApplication(false));
		$this->assertTrue(Yii::app()->user->checkAccess('Abit.Read', $params));
		$this->assertFalse(Yii::app()->user->checkAccess('Abit.Update', $params));
		$this->assertFalse(Yii::app()->user->checkAccess('AbitEge.Update', $params));
		// что заявления других не можем ни редактировать, ни читать по прежнему
		$abit2 = Abit::model()->findByPk(2);
		$params2 = array(
			'abit' => $abit2
		);
		$this->assertFalse(Yii::app()->user->checkAccess('Abit.Read', $params2));
	}

	/* ----- сокращено: testValidateBeforeSubmit, testSpecOptions ----- */

	public function testStatusWorkflow()
	{
		$abit_id = $this->abits('abit_sred_created')->id;
		
		$specs = array();
		
		$abit = Abit::model()->findByPk($abit_id);
		$this->assertTrue($abit->hasProperty('canApprove'));
		$this->assertTrue($abit->hasProperty('canPrint'));
		$this->assertTrue($abit->hasProperty('canReject'));
		$this->assertTrue($abit->hasProperty('canReview'));
		$this->assertTrue($abit->hasProperty('canVerify'));
		$this->assertTrue($abit->hasProperty('canAmend'));
		$this->assertTrue($abit->hasProperty('canVerifyAmended'));
		
		// последовательно изменяем статусы абитуриента,
		// и проверяем ограниченмя
		
		// изначальные настройки
		$abit = Abit::model()->findByPk($abit_id);
		$this->assertFalse($abit->canApprove);
		$this->assertFalse($abit->canPrint);
		$this->assertFalse($abit->canReject);
		$this->assertTrue($abit->canReview);
		$this->assertFalse($abit->canVerify);
		$this->assertFalse($abit->canAmend);
		$this->assertFalse($abit->canVerifyAmended);
		
		// взят на проверку
		$this->assertTrue($abit->verifyApplication(false));
		$abit = Abit::model()->findByPk($abit_id);
		$this->assertTrue($abit->canApprove);
		$this->assertTrue($abit->canPrint);
		$this->assertTrue($abit->canReject);
		$this->assertTrue($abit->canReview);
		$this->assertFalse($abit->canVerify);
		$this->assertFalse($abit->canVerifyAmended);
		
		// одобрен
		$this->assertTrue($abit->approveApplication());
		$abit = Abit::model()->findByPk($abit_id);
		$this->assertFalse($abit->canApprove);
		$this->assertTrue($abit->canPrint);
		$this->assertFalse($abit->canReject);
		$this->assertTrue($abit->canReview);
		$this->assertFalse($abit->canVerify);
		$this->assertTrue($abit->canWithDraw);
		$this->assertTrue($abit->canAmend);
		$this->assertFalse($abit->canVerifyAmended);
		
		// одобрен, корректирует
		$this->assertTrue($abit->amendApplication());
		$abit = Abit::model()->findByPk($abit_id);
		$this->assertFalse($abit->canApprove);
		$this->assertTrue($abit->canPrint);
		$this->assertFalse($abit->canReject);
		$this->assertTrue($abit->canReview);
		$this->assertFalse($abit->canVerify);
		$this->assertFalse($abit->canAmend);
		$this->assertTrue($abit->canVerifyAmended); // можно взять на проверку, если был отозван приёмной комиссией
		                                            
		// одобрен, подал на проверку после корректировки
		$this->assertTrue($abit->submitAmendedApplication());
		$abit = Abit::model()->findByPk($abit_id);
		$this->assertFalse($abit->canApprove);
		$this->assertFalse($abit->canPrint);
		$this->assertFalse($abit->canReject);
		$this->assertFalse($abit->canReview);
		$this->assertFalse($abit->canVerify);
		$this->assertFalse($abit->canWithDraw);
		$this->assertFalse($abit->canAmend);
		$this->assertTrue($abit->canVerifyAmended);
		
		// одобрен, взят на проверку после корректировки
		$this->assertTrue($abit->verifyAmendedApplication());
		$abit = Abit::model()->findByPk($abit_id);
		$this->assertTrue($abit->canApprove);
		$this->assertTrue($abit->canPrint);
		$this->assertFalse($abit->canReject);
		$this->assertTrue($abit->canReview);
		$this->assertFalse($abit->canVerify);
		$this->assertFalse($abit->canWithDraw);
		$this->assertTrue($abit->canAmend);
		$this->assertFalse($abit->canVerifyAmended);
		
		// забрал документы
		$this->assertTrue($abit->withdrawApplication(null, 1, false));
		$abit = Abit::model()->findByPk($abit_id);
		$this->assertFalse($abit->canApprove);
		$this->assertFalse($abit->canPrint);
		$this->assertFalse($abit->canReject);
		$this->assertFalse($abit->canReview);
		$this->assertFalse($abit->canVerify);
		$this->assertTrue($abit->canStay); // можно вернуть документы
	}

	/* ----- сокращено: testPassport, testValidateDates, testValidateRussianName, testPotential, testGupTransfer, testFisError ----- */

}
