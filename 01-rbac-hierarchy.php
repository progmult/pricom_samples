<?php
/**
 * ============================================================================
 * ПРИМЕР 1. RBAC: иерархия прав и бизнес-правила на уровне записи
 * ----------------------------------------------------------------------------
 * Источник: protected/commands/shell/RbacCommand.php
 *
 * Двухслойная модель доступа:
 *  - статический слой: операции ("Сущность.Действие") собираются в роли, роли
 *    наследуют друг друга (pricom-dir -> pricom -> операции);
 *  - динамический слой: bizRule — PHP-выражение, которое выполняется в момент
 *    проверки и получает $params с конкретными моделями. Оно отвечает на
 *    вопрос "можно ли редактировать ИМЕННО ЭТУ запись", т.е. поверх RBAC
 *    получается ABAC.
 *
 * Роли жизненного цикла (author -> underApproval -> inContest) перевыдаются
 * при смене статуса заявления, см. пример 05.
 * ============================================================================
 */

class RbacCommand extends CConsoleCommand
{
	private $_authManager;
	public function getHelp()
	{
		return "
USAGE
	rbac
DESCRIPTION
	This command generates an initial RBAC authorization hierarchy.";
	}

	/**
	 * Выполнение действия.
	 * @param массив параметров командной строки, специфичных для данной команды
	 */
	public function run($args)
	{
		//убедиться, что authManager включен, так как это обязательно для создания иерархии прав.
		if(($this->_authManager=Yii::app()->authManager)===null)
		{
			echo "Error: an authorization manager, named 'authManager' must be configured to use this command.\n";
			echo "If you already added 'authManager' component in application configuration,\n";
			echo "please quit and re-enter the yiic shell.\n";
			return;
		}

		//предоставить шанс отменить запрос
		echo "This command will create two roles: Author and Reader and the following premissions:\n";
		echo "create, read, update and delete user\n";
		echo "create, read, update and delete abit\n";
		echo "create, read, update and delete abit-related records in other tables\n";
		echo "Would you like to continue? [Yes|No] ";

		//проверить, что пользователь ввел Y, означающее "ДА", на предыдущий вопрос
		if(!strncasecmp(trim(fgets(STDIN)),'y',1))
		{
			//сперва нужно удалить все операции, роли, связи и назначения
			$this->_authManager->clearAll();
			//создать низкоуровневые операции для users
			$this->_authManager->createOperation("User.Create","create a new user");
			$this->_authManager->createOperation("User.Read","read user profile information");
			$this->_authManager->createOperation("User.Update","update a users information");
			$this->_authManager->createOperation("User.Delete","remove a user from a project");
			//создать низкоуровневые операции для Abiturients
			$this->_authManager->createOperation("Abit.Create","create a new abit");
			$this->_authManager->createOperation("Abit.Read","read abit information");
			$this->_authManager->createOperation("Abit.Update","update abit information");
			$this->_authManager->createOperation("Abit.Delete","delete a abit");
			$this->_authManager->createOperation("Abit.Admin","admin abit information");
			$this->_authManager->createOperation("Abit.Review","Review abit information");
			$this->_authManager->createOperation("Abit.PrintStatement","print out statement");
			$this->_authManager->createOperation("Abit.PrintRegCard","print out registration Card");
			$this->_authManager->createOperation("Abit.PrintRegCardDist","print out registration card (distance)");
			$this->_authManager->createOperation("Abit.PrintPersonnel","print out personnel questionnaire");
			$this->_authManager->createOperation("Abit.Referral","добавлять реферрала к абитуриенту");
			$this->_authManager->createOperation("Abit.Note","добавлять комментарий к абитуриенту");
			$this->_authManager->createOperation("Abit.Potential","Изменять признак потенциального абитуриента");
			$this->_authManager->createOperation("Abit.Decision","Изменять статус решения абитуриента");
			//создать низкоуровневые операции для AbitAchievement
			$this->_authManager->createOperation("AbitAchievement.Read","Просматривать индивидуальные достижения абитуриента");
			$this->_authManager->createOperation("AbitAchievement.Create","Создавать индивидуальные достижения абитуриента");
			$this->_authManager->createOperation("AbitAchievement.Update","Редактировать индивидуальные достижения абитуриента");
			$this->_authManager->createOperation("AbitAchievement.Delete","Удалять индивидуальные достижения абитуриента");

	/* ----- сокращено: операции AbitAddress, AbitContact, AbitEge, AbitFamily, AbitFile, AbitJob, AbitLang, AbitParent, AbitPrepare, AbitPrize, AbitSchool, AbitSpec, AbitTravel — по той же схеме ----- */

			//создать операции для отчетов
			$this->_authManager->createOperation("Report.Read","Просматривать графики-отчеты");

	/* ----- сокращено: операции для модуля потенциальных абитуриентов (Potential*, TypePotential*) ----- */

			//создать роль "reader" (автор заявления) и добавить нужные разрешения (в виде потомков) к этой роли
			$role=$this->_authManager->createRole("reader");
			$role->addChild("Abit.Read");
			$role->addChild("AbitAddress.Read");
			$role->addChild("AbitContact.Read");
			$role->addChild("AbitEge.Read");
			$role->addChild("AbitFamily.Read");
			$role->addChild("AbitFile.Read");
			$role->addChild("AbitJob.Read");
			$role->addChild("AbitLang.Read");
			$role->addChild("AbitParent.Read");
			$role->addChild("AbitPrepare.Read");
			$role->addChild("AbitPrize.Read");
			$role->addChild("AbitSchool.Read");
			$role->addChild("AbitSpec.Read");
			$role->addChild("AbitTravel.Read");
			$role->addChild("Report.Read");
			//создать роль "author" и добавить нужные разрешения, как и роль "reader", в виде потомков
			//бизнес-правило дает пользователю права "автора" только на созданного им абитуриента и
			//связанные с ним записи
			$bizRule = 'return isset($params["abit"]) && $params["abit"]->isUserInRole("author") && (!isset($params["relModel"]) || $params["abit"]->id == $params["relModel"]->abit_id);';
			$role=$this->_authManager->createRole("author", "Author of Application for Admission", $bizRule);
			$role->addChild("reader");
			$role->addChild("Abit.Update");
			$role->addChild("Abit.PrintStatement");
			$role->addChild("Abit.PrintRegCard");
			$role->addChild("Abit.PrintRegCardDist");
			$role->addChild("Abit.PrintPersonnel");
			$role->addChild("AbitAddress.Update");
			$role->addChild("AbitContact.Update");
			$role->addChild("AbitFile.Create");
			$role->addChild("AbitFile.Update");
			$role->addChild("AbitFile.Delete");
			$role->addChild("AbitParent.Update");
			$role->addChild("AbitPrepare.Update");
			$role->addChild("AbitEge.Create");
			$role->addChild("AbitEge.Update");
			$role->addChild("AbitEge.Delete");
			$role->addChild("AbitFamily.Create");
			$role->addChild("AbitFamily.Update");
			$role->addChild("AbitFamily.Delete");
			$role->addChild("AbitJob.Create");
			$role->addChild("AbitJob.Update");
			$role->addChild("AbitJob.Delete");
			$role->addChild("AbitLang.Create");
			$role->addChild("AbitLang.Update");
			$role->addChild("AbitLang.Delete");
			$role->addChild("AbitPrize.Create");
			$role->addChild("AbitPrize.Update");
			$role->addChild("AbitPrize.Delete");
			$role->addChild("AbitSchool.Create");
			$role->addChild("AbitSchool.Update");
			$role->addChild("AbitSchool.Delete");
			$role->addChild("AbitSpec.Create");
			$role->addChild("AbitSpec.Update");
			$role->addChild("AbitSpec.Delete");
			$role->addChild("AbitTravel.Create");
			$role->addChild("AbitTravel.Update");
			$role->addChild("AbitTravel.Delete");
			// создать роль "pricom" и добавить права
			$role=$this->_authManager->createRole("pricom");
			$role->addChild("Abit.Admin");
			$role->addChild("Abit.Review");
			$role->addChild("Abit.PrintStatement");
			$role->addChild("Abit.PrintRegCard");
			$role->addChild("Abit.PrintRegCardDist");
			$role->addChild("Abit.PrintPersonnel");
			$role->addChild("Abit.Potential");
			$role->addChild("AbitFile.Print");
			$role->addChild("AbitFile.Read");
			$role->addChild("Report.Read");
			// создать роль "pricom-dir" и добавить права
			$role=$this->_authManager->createRole("pricom-dir");
			$role->addChild("pricom");
			$role->addChild("Contest.Admin", "Управлять конкурсным отбором");
			$role->addChild("Abit.Referral", "Устанавливать что привез абитуриента");
			$role->addChild("Abit.Note", "Добавлять комментаций к абитуриенту");
			$role->addChild("AbitSpec.Diplom", "Связывать специальность и документ об образовании");
			$role->addChild("AbitSchool.Foreign", "Устанавливать для учебного заведения признак - иностранное");
			$role->addChild("Abit.Decision");
			$role->addChild("AbitSpec.Admin","Управлять специальностями-заявлениями");
			$role->addChild("Abit.Stok","Управлять признаком передан в СтОК");
			
			// создать роль для проверяемого и добавить ей права, как у "reader", но только на одного абитуриента
			$bizRule = 'return isset($params["abit"]) && $params["abit"]->isUserInRole("underApproval") && (!isset($params["relModel"]) || $params["abit"]->id == $params["relModel"]->abit_id);';
			$role=$this->_authManager->createRole("underApproval", "Author of Application for Admission, which is under approval", $bizRule);
			$role->addChild("reader");

			// создать базовые роли для следующих этапов
			// участвует в конкурсе
			$bizRule = 'return isset($params["abit"]) && $params["abit"]->isUserInRole("inContest") && (!isset($params["relModel"]) || $params["abit"]->id == $params["relModel"]->abit_id);';
			$role=$this->_authManager->createRole("inContest", 'Author of Application for Admission, which is contest',$bizRule);
			$role->addChild("reader");
			// отказался от конкурса
			$bizRule = 'return isset($params["abit"]) && $params["abit"]->isUserInRole("withdrawContest") && (!isset($params["relModel"]) || $params["abit"]->id == $params["relModel"]->abit_id);';
			$role=$this->_authManager->createRole("withdrawContest",'Author of Application for Admission, which is withdraw documents',$bizRule);
			$role->addChild("reader");

	/* ----- сокращено: роли pricom-potential и pricom-potential-dir ----- */

			// данные роли пока не используются
			$role=$this->_authManager->createRole("winContest");
			$role=$this->_authManager->createRole("failContest");
			$role=$this->_authManager->createRole("student");

			//предоставить сообщение об оспешном завершении команды
			echo "Authorization hierarchy successfully generated.";
		}
	}
}