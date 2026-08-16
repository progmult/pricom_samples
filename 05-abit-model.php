<?php
/**
 * ============================================================================
 * ПРИМЕР 5. Модель абитуриента: условная валидация и машина состояний
 * ----------------------------------------------------------------------------
 * Источник: protected/models/Abit.php (2857 строк).
 *
 *  1. Условная валидация: набор правил для серии/номера паспорта и гражданства
 *     зависит от типа документа (паспорт РФ / загранпаспорт / иностранный).
 *     Сделано декларативно, валидатором-переключателем, а не ветвлением в
 *     beforeValidate — иначе разъехалась бы клиентская валидация.
 *  2. Права на конкретное заявление: методы isUserInRole / associateUserToRole
 *     вызываются из bizRule ролей author / underApproval / inContest и
 *     выполняются на каждой проверке доступа, поэтому написаны прямыми
 *     запросами с bindValue, без загрузки AR-моделей.
 *  3. Машина состояний. Смена статуса — не просто UPDATE поля: вместе со
 *     статусом перевыдаются роли RBAC, поэтому "заявление на проверке —
 *     редактировать нельзя" держится на правах, а не на скрытой кнопке.
 *     Отклонение — единственный переход, возвращающий права автору назад.
 *
 * Объявление класса добавлено, чтобы фрагмент оставался разбираемым;
 * относящиеся к делу методы приведены как есть.
 * ============================================================================
 */

class Abit extends CActiveRecord
{
	/**
	 * @return string the associated database table name
	 */
	public function tableName()
	{
		return '{{abit}}';
	}

	public function behaviors()
	{
		return array(
			'ERememberFiltersBehavior' => array(
				'class' => 'application.components.ERememberFiltersBehavior',
				'defaults' => array(),           /* optional line */
				'defaultStickOnClear' => false   /* optional line */
			),
			'AutoDateFormat' => array(
				'class' => 'application.components.behaviors.MdmAutoDateBehavior',
				'logicalFormat' => 'd.m.Y', // optional, default d-m-Y
				'physicalFormat' => 'Y-m-d', // optional, default Y-m-d
				'attributes' => array(
					'passportDate' => 'passport_date', // mapping attribute from logical to physical filed.
					'birthDate' => 'birthday'
				)
			)
		);
	}

	/**
	 * @return array validation rules for model attributes.
	 */
	public function rules()
	{
		// NOTE: you should only define rules for those attributes that
		// will receive user inputs.
		return array(
		array('firstname, lastname, passport_department, passport_no, passport_ser, phone, type_passport_id, selected_lang_id, passportDate, birthDate', 'required'),
		array('filial_id, learn_year, citizen_id, is_confirm_enrollrules, is_confirm_personal, is_ds, is_firsthigh, is_from_krym, is_hidden, is_transferred, nation_id, selected_lang_id, send_toother, stud_no_potential, type_fis_disability_id, type_leveledu_id, type_married_id, type_passport_id, type_potential_id, type_region_id, type_reject_id, type_sex_id, type_social_id, type_status_id, type_fis_return_documents_id', 'numerical', 'integerOnly'=>true),
		array('is_confirm_enrollrules','in', 'range' => array(1), 'message'=>Yii::t('abit', 'You must confirm enroll rules')),
		array('is_confirm_personal','in', 'range' => array(1), 'message'=>Yii::t('abit', 'You must agree to the processing of data')),
		array('academic_rank, birthplace, childs, fathername, firstname, job_long, is_hidden_comment, lastname, marriage_partner, passport_department, passport_department_code, pricom_referral, prizes, skills_car, skills_pc, skills_war, skills_war_branch, skills_war_org, reject_comment, withdraw_comment', 'length', 'max'=>255),
		array('pricom_note', 'length', 'max'=>2000),
		array('create_ip', 'length', 'max'=>15),
		array('passportDate, birthDate, phone, passport_date, birthday', 'safe'),
		array('birthDate', 'ext.validators.DateTimeCompareValidator', 'compareValue'=>GupHelper::min_birhday(), 'format'=>'d.m.Y', 'operator'=>'<=', 'allowEmpty'=>false , 'message'=>Yii::t('abit', 'You should probably be a little older')),
		array('birthDate', 'ext.validators.DateTimeCompareValidator', 'compareValue'=>GupHelper::max_birthday(), 'format'=>'d.m.Y', 'operator'=>'>=', 'allowEmpty'=>false , 'message'=>Yii::t('abit', 'May you be a little younger?')),
		array('passportDate', 'ext.validators.DateTimeCompareValidator', 'compareValue'=>GupHelper::max_passport_day(), 'format'=>'d.m.Y', 'operator'=>'>=', 'allowEmpty'=>false , 'message'=>Yii::t('abit', 'Wrong passport date')),
		// Поля "серия" и "номер" паспорта, а также гражданство зависят от типа паспорта
		array('type_passport_id', 'ext.validators.FSwitchValidator',
	        'cases'=>array(
	        	// паспорт гражданина РФ
	           '1'=>array(
		                array('passport_ser, passport_no', 'numerical', 'integerOnly'=>true),
		                array('passport_ser', 'length', 'is'=>4),
		                array('passport_no', 'length', 'is'=>6),
	           			array('firstname, lastname, fathername', 'ext.validators.russianNamingValidator.RussianNamingValidator','message'=>'Недопустимые символы.'),
	           			array('firstname, lastname, fathername', 'ext.validators.russianNamingValidator.RussianNamingCaseValidator','message'=>'Повторяющиеся заглавные буквы недостимы.'),
					   /// гражданство - Россия
					   array('citizen_id', 'in', 'range'=>GupOKCitizen::russiaIds(), 'message' => 'Если российский паспорт, то и гражданство - Россия'),
	        	),
	        	// заграничный паспорт гражданина РФ
	        	'2'=>array(
	        			array('passport_ser, passport_no', 'numerical', 'integerOnly'=>true),
	        			array('passport_ser', 'length', 'is'=>2),
	        			array('passport_no', 'length', 'is'=>7),
						/// гражданство - Россия
						array('citizen_id', 'in', 'range'=>GupOKCitizen::russiaIds(), 'message' => 'Если российский загранпаспорт, то и гражданство - Россия'),
	        	),
	        ),
        	// иностранный паспорт
        	'default'=>array(
	        		array('passport_no', 'length', 'max'=>20),
	        		array('passport_ser', 'length', 'max'=>20),
					/// гражданство - не Россия
					array('citizen_id', 'in', 'range'=>GupOKCitizen::russiaIds(), 'not'=>true, 'message' => 'Если иностранный паспорт, то гражданство - не Россия'),
        	)
   		),
   		array('passportDate', 'date', 'format'=>'dd.MM.yyyy', 'message'=>Yii::t('abit', 'PassportDate format must by DD.MM.YYYY i.e. (14.01.1990)')),
   		array('birthDate', 'date', 'format'=>'dd.MM.yyyy', 'message'=>Yii::t('abit', 'Birthday format must by DD.MM.YYYY i.e. (14.01.1990)')),
		// The following rule is used by search().
		// Please remove those attributes that should not be searched.
		array('filial_id','unsafe'),
		array('id, learn_year, academic_rank, birthday, birthplace, childs, citizen_id, fathername, firstname, is_confirm_enrollrules, is_confirm_personal, is_ds, is_firsthigh, is_hidden, is_transferred, job_long, lastname, marriage_partner, nation_id, passport_date, passport_department, passport_department_code, passport_no, passport_ser, prizes, selected_lang_id, send_toother, skills_car, skills_pc, skills_war, skills_war_branch, skills_war_org, stud_no, transfer_time, type_leveledu_id, type_married_id, type_passport_id, type_region_id, type_sex_id, type_social_id, type_status_id, approve_time, approve_user_id, create_time, create_user_id, reject_comment, withdraw_comment, reject_time, reject_user_id, submit_time, update_time, update_user_id, update_user_id, verify_time, verify_user_id, fullname, search_spec_id, search_learnform_id, search_mobile_phone, search_cur_city, search_fis_error, search_same_passport', 'safe', 'on'=>'search'),
		// Правило валидации для элемента "Нет отчества".
        array('is_no_fathername', 'ext.validators.FSwitchValidator',
            'cases'=>array(
                '0'=>array('fathername', 'required'),
                '1'=>array('fathername', 'length', 'is'=>0, 'message'=>'Для сохранения поля снимите флажок "Нет отчества"')
            )
        )
        );
	}

	/**
	 * @return array relational rules.
	 */

	/* ----- сокращено: relations(), attributeLabels(), search() и ~30 методов-геттеров справочников ----- */

	/**
	 * Создает связь между абитуриентом, пользователем и правами над абитуриентом.
	 * @param $role
	 * @param $userId
	 * @return int
	 * @throws CDbException
	 */
	public function associateUserToRole($role, $userId)
	{
		$sql = "INSERT INTO pricom_abit_user_role (abit_id, user_id, role)
			VALUES (:abitId, :userId, :role)";
		$command = Yii::app()->db->createCommand($sql);
		$command->bindValue(":abitId", $this->id, PDO::PARAM_INT);
		$command->bindValue(":userId", $userId, PDO::PARAM_INT);
		$command->bindValue(":role", $role, PDO::PARAM_STR);
		return $command->execute();
	}

	/**
	 * Удаляет связь между абитуриентом, пользователем и правами над абитуриентом.
	 * @param $role
	 * @param $userId
	 * @return int
	 * @throws CDbException
	 */
	public function removeUserFromRole($role, $userId)
	{
		$sql = "DELETE FROM pricom_abit_user_role WHERE
				abit_id=:abitId AND user_id=:userId AND role=:role";
		$command = Yii::app()->db->createCommand($sql);
		$command->bindValue(":abitId", $this->id, PDO::PARAM_INT);
		$command->bindValue(":userId", $userId, PDO::PARAM_INT);
		$command->bindValue(":role", $role, PDO::PARAM_STR);
		return $command->execute();
	}

	/**
	 * Находится ли пользователь в указанной роли в контекте этого абитуриента
	 * @param $role
	 * @param null $relatedModel
	 * @return bool
	 * @throws CDbException
	 */
	public function isUserInRole($role, $relatedModel = null)
	{
		$sql = "SELECT role FROM pricom_abit_user_role WHERE
				abit_id=:abitId AND user_id=:userId AND role=:role";
		$command = Yii::app()->db->createCommand($sql);
		$command->bindValue(":abitId", $this->id, PDO::PARAM_INT);
		$command->bindValue(":userId", Yii::app()->user->getId(), PDO::PARAM_INT);
		$command->bindValue(":role", $role, PDO::PARAM_STR);
		return $command->execute()==1 ? true : false;
	}

	/**
	 * Помечает абитуриента, как отправленного на проверку.
	 * Возвращает 1, если все верно, и 0, если произошла ошибка.
	 */

	/* ----- сокращено: submitApplication() — подача заявления абитуриентом ----- */

	/**
	 * Помечает абитуриента, как взятого на проверку.
	 * Возвращает 1, если все верно, и 0, если произошла ошибка.
	 *
	 * @param bool $validate
	 * @return bool
	 * @throws CDbException
	 */
	public function verifyApplication($validate = true)
	{
		if ($validate && !$this->canVerify) return false;

		$this->type_status_id = 20;
		$this->verify_time = date('Y-m-d H:i:s');
		$this->verify_user_id = Yii::app()->user->id;
		if ($this->save())
		{
			$userId = $this->create_user_id;
			$auth = Yii::app()->authManager;
			// TODO: вызывать revoke и assign из методов removeUserFromRole и associateUserToRole соответственно
			// убрать у абитуриента право "автора"
			$role = 'author';
			$this->removeUserFromRole($role,$userId);
			$auth->revoke($role,$userId);
			// дать абитуриенту право "underApproval"
			$role = 'underApproval';
			$this->associateUserToRole($role,$userId);
			$auth->assign($role,$userId);
			return true;
		}

		else return false;
	}

	/**
	 * Помечает абитуриента, как отклоненного.
	 * Помечает выбранные абитуриентом специальности как "отклоненные".
	 * Возвращает 1, если все верно, и 0, если произошла ошибка.
	 */
	public function rejectApplication($reject_comment, $type_reject_id = null, $validate = true)
	{
		if ($validate && !$this->canReject) return false;

		$this->type_status_id = 5;
		$this->reject_comment = $reject_comment;
		$this->type_reject_id = $type_reject_id;
		$this->reject_time = date('Y-m-d H:i:s');
		$this->reject_user_id = Yii::app()->user->id;
		if ($this->save())
		{
			$userId = $this->create_user_id;
			$auth = Yii::app()->authManager;

			// убрать у абитуриента право "underApproval"
			$role = 'underApproval';
			$this->removeUserFromRole($role,$userId);
			$auth->revoke($role,$userId);

			// дать абитуриенту право "автора"
			$role = 'author';
			$this->associateUserToRole($role,$userId);
			$auth->assign($role,$userId);

			// убрать у абитуриента право "inContest"
			$role = 'inContest';
			$this->removeUserFromRole($role,$userId);
			$auth->revoke($role,$userId);

			// TODO: вызывать revoke и assign из методов removeUserFromRole и associateUserToRole соответственно
			
			// пометить выбранные абитуриентом специальности как "отклоненные"
			foreach ($this->abitSpecs as $abitSpec) {
				// кроме отозванных
				if (!$abitSpec->isWithdraw) {
					$abitSpec->reject();
				}
			}

			return true;
		}
		else return false;
	}

	/**
	 * Помечает абитурента, как одобреннного к участию в конкурсе.
	 * Помечает выбранные абитуриентом специальности как "одобренные".
	 * Возвращает 1, если все верно, и 0, если произошла ошибка.

	/* ----- сокращено: approveApplication, withdrawApplication, amendApplication, обмен с ФИС, печатные формы ----- */

}
