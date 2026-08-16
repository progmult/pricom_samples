<?php
/**
 * ============================================================================
 * ПРИМЕР 2. Мультиарендность: роль действует только "в своём" филиале
 * ----------------------------------------------------------------------------
 * Источники: protected/models/FilialUserForm.php,
 *            protected/models/GupFilial.php (фрагмент)
 *
 * У вуза несколько филиалов, сотрудник может работать в одном или сразу в
 * нескольких, роль при этом одна и та же. Чтобы не плодить роли вида
 * "pricom-filial-1", назначение роли (AuthAssignment) получает собственный
 * bizRule, который спрашивает у филиала из $params: числится ли пользователь
 * здесь в этой роли. Связь пользователь-филиал-роль лежит в таблице
 * filial_user_role.
 *
 * Итог: роль в иерархии одна, а область её действия задана данными.
 * Значение $params['filial'] подставляет базовый контроллер (пример 03).
 * ============================================================================
 */

/**
 * FilialUserForm class.
 * FilialUserForm is the data structure for keeping
 * the form data related to adding an existing user to a filial. It is used by the 'addUser' action of 'FilialController'.
*/
class FilialUserForm extends CFormModel
{
	/**
	 * @var string username of the user being added to the filial
	 */
	public $username;

	/**
	 * @var string the role to which the user will be associated within the filial
	 */
	public $role;

	/**
	 * @var object an instance of the Filial AR model class
	 */
	public $filial;

	private $_user;
	private $_auth;

	/**
	 * Declares the validation rules.
	 * The rules state that username and role are required.
	 */
	public function rules()
	{
		return array(
			// username and role are required
			array('username, role', 'required'),
			// username needs to be checked for existence (LDAP account only allowed).
			array('username', 'exist', 'className'=>'User', 'criteria'=>array('condition'=>'auth_service_name = "ldap"')),
			// user must not already exist in filial (except removing role)
			array('username', 'inFilial','except'=>array('remove')),
		);
	}



	/* ----- сокращено: закомментированный метод verify() — ранняя версия проверки ----- */

	
	/**
	 * Check the association between the user, role and filial
	 * This is the 'inFilial' validator as declared in rules().
	 */
	public function inFilial($attribute,$params)
	{
		if(!$this->hasErrors())  // we only want to check when no other input errors are present
		{
			if ($this->filial->isUserInFilial($this->_user->id))
			{
				$this->addError('username','This user has already been added to the filial.');
			}
		}
	}
	
	/**
	 * Создает связь пользователя, роли и филиала
	 */
	public function assign()
	{
		if($this->_user instanceof User)
		{
			//assign the user, in the specified role, to the filial
			$this->filial->associateUserToRole($this->role, $this->_user->id);
			//add the association, along with the RBAC biz rule, to our RBAC hierarchy (if not already have) this role
			if (!$this->_auth->isAssigned($this->role, $this->_user->id)) {
				$bizRule='return isset($params["filial"]) && $params["filial"]->isCurrentUserInRole("'.$this->role.'");';
				$this->_auth->assign($this->role,$this->_user->id, $bizRule);
			}
			return true;
		}
		else
		{
			$this->addError('username','Error when attempting to assign this user to the filial.');
			return false;
		}

	}
	
	/**
	 *  Удяляет связь пользователя, роли и филиала
	 */
	public function dissociate()
	{
		if($this->validate() && $this->_user instanceof User)
		{
			// убрать связь пользователя с ролью и филиалом.
			$result = ($this->filial->removeUserFromRole($this->role, $this->_user->id) === 1) ? true : false;
			// удалить связь с ролью из RBAC, если более не связан ни с одним филиалом
			if ($result && !$this->filial->isUserInRoleInOtherFilials($this->role,$this->_user->id))
			{
				$this->_auth->revoke($this->role, $this->_user->id);
			}
			return $result;
		}
		else
		{
			$this->addError('username','Error when attempting to remove this user from the filial.');
			return false;
		}
	}

	/**
	 * Generates an array of usernames to use for the autocomplete
	 */
	public function createUsernameList()
	{
		$sql = "SELECT username FROM pricom_user where auth_service_name = 'ldap'";
		$command = Yii::app()->db->createCommand($sql);
		$rows = $command->queryAll();
		//format it for use with auto complete widget
		$usernames = array();
		foreach($rows as $row)
		{
			$usernames[]=$row['username'];
		}
		return $usernames;
	}
	
	protected function beforeValidate()
	{
		$this->loadUser();
		$this->_auth = Yii::app()->authManager;
		
		if(!$this->_user instanceof User)
		{
			return false;
		}
	
		return parent::beforeValidate();
	}
	
	/**
	 * Loads user by username.
	 */
	private function loadUser()
	{
		$user = User::model()->findByAttributes(array(
			'username' => $this->username,
			'auth_service_name' => 'ldap'
		));
		$this->_user = $user;
		// создать несуществующего юзера
		if (empty($this->_user)) {
			// только, если есть в LDAP
			
			// collect AD info about users
			$info = Yii::app()->ldap->user()->infoCollection($this->username, array(
				"samaccountname",
				"mail",
				"memberof",
				"department",
				"displayname",
				"telephonenumber",
				"primarygroupid",
				"objectsid"
			));
			
			$this->_user = new User('ldap');
			$this->_user->email = $info->mail;
			$this->_user->username = $this->username;
			$this->_user->password = 'ldappassword';
			$this->_user->password_repeat = $this->_user->password;
			$this->_user->auth_service_name = 'ldap';
			$this->_user->save();
		}
	}
}

/* ============================================================
 * Фрагмент protected/models/GupFilial.php — работа со связью
 * филиал-пользователь-роль. Эти методы вызывает bizRule назначения.
 * Объявление класса добавлено, чтобы фрагмент оставался разбираемым;
 * остальные методы модели опущены.
 * ============================================================ */

class GupFilial extends CActiveRecord
{
    /**
     * Создает связь между филиалом, пользователем и правами
     * над филиалом.
     */
    public function associateUserToRole($role, $userId)
    {
        $sql = "INSERT INTO pricom_filial_user_role (filial_id, user_id, role)
			VALUES (:filialId, :userId, :role)";
        $command = Yii::app()->db->createCommand($sql);
        $command->bindValue(":filialId", $this->id, PDO::PARAM_INT);
        $command->bindValue(":userId", $userId, PDO::PARAM_INT);
        $command->bindValue(":role", $role, PDO::PARAM_STR);
        return $command->execute();
    }

    /**
     * Удаляет связь между филиалом, пользователем и правами
     * над филиалом.
     */
    public function removeUserFromRole($role, $userId)
    {
        $sql = "DELETE FROM pricom_filial_user_role WHERE
				filial_id=:filialId AND user_id=:userId AND role=:role";
        $command = Yii::app()->db->createCommand($sql);
        $command->bindValue(":filialId", $this->id, PDO::PARAM_INT);
        $command->bindValue(":userId", $userId, PDO::PARAM_INT);
        $command->bindValue(":role", $role, PDO::PARAM_STR);
        return $command->execute();
    }

    /**
     * @param $role
     * @param null $relatedModel
     * @return bool находится ли текущий пользователь в указанной роли в контекте этого филиала
     * @throws CDbException
     */
    public function isCurrentUserInRole($role, $relatedModel = null)
    {
        $sql = "SELECT role FROM pricom_filial_user_role WHERE
				filial_id=:filialId AND user_id=:userId AND role=:role";
        $command = Yii::app()->db->createCommand($sql);
        $command->bindValue(":filialId", $this->id, PDO::PARAM_INT);
        $command->bindValue(":userId", Yii::app()->user->getId(), PDO::PARAM_INT);
        $command->bindValue(":role", $role, PDO::PARAM_STR);
        return $command->execute() == 1 ? true : false;
    }
    
    /**
     * @param $user_id
     * @return bool находится ли вообще пользователь в любой роли в контекте этого филиала
     * @throws CDbException
     */
    public function isUserInFilial($user_id)
    {
        $sql = "SELECT user_id FROM pricom_filial_user_role WHERE
				filial_id=:filialId AND user_id=:userId";
        $command = Yii::app()->db->createCommand($sql);
        $command->bindValue(":filialId", $this->id, PDO::PARAM_INT);
        $command->bindValue(":userId", $user_id, PDO::PARAM_INT);
        return $command->execute() == 1 ? true : false;
    }

    /**
     * @param $role
     * @param $user_id
     * @return bool находится ли вообще пользователь указанной роли в других филиалах
     * @throws CDbException
     */
    public function isUserInRoleInOtherFilials($role, $user_id)
    {
        $sql = "SELECT role FROM pricom_filial_user_role WHERE
				filial_id<>:filialId AND user_id=:userId AND role=:role";
        $command = Yii::app()->db->createCommand($sql);
        $command->bindValue(":filialId", $this->id, PDO::PARAM_INT);
        $command->bindValue(":userId", $user_id, PDO::PARAM_INT);
        $command->bindValue(":role", $role, PDO::PARAM_STR);
        return $command->execute() > 0 ? true : false;
    }

    /**
     * @return array - список доступных для выбора ролей
     */
    public static function getRoleOptions()
    {
        return array('pricom' => 'pricom', 'pricom-dir' => 'pricom-dir');
    }
}
