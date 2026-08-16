<?php
/**
 * ============================================================================
 * ПРИМЕР 9. Миграции: схема, справочники и права как версионируемый код
 * ----------------------------------------------------------------------------
 * Источники: protected/migrations/ (95 миграций за время жизни проекта).
 * Три характерные миграции собраны в один файл; в проекте каждая лежит
 * в своём файле с временной меткой в имени.
 *
 *  1. Миграцией едет не только схема, но и справочные данные, и права доступа:
 *     права — это строки в AuthItem / AuthItemChild, такие же данные.
 *     Развёртывание на новом стенде = прогон миграций, без ручных INSERT-ов.
 *  2. Каждая миграция обратима, миграции прав откатывают ровно свою строку,
 *     а не пересобирают всю иерархию.
 *  3. Учтены различия СУБД: боевая база MySQL/InnoDB, тесты гоняются на SQLite,
 *     который не умеет добавлять внешние ключи к готовой таблице.
 *  4. COMMENT у каждой колонки: данные читают ещё и аналитики напрямую в SQL,
 *     и такая документация не разъезжается с базой.
 *  5. order_abit — не просто связь приказ-абитуриент: туда копируются условия
 *     зачисления на момент издания приказа. Приказ юридический документ, он не
 *     должен "поехать" вслед за правкой справочника через год.
 *  6. Третья миграция — перенос права Abit.Stok от оператора к руководителю.
 *     Ни один контроллер при этом не менялся: проверка checkAccess осталась
 *     прежней, изменилась только иерархия ролей.
 * ============================================================================
 */


/* ============================================================
 * Файл: protected/migrations/m160711_122441_table_admission.php
 * ============================================================ */


class m160711_122441_table_admission extends CDbMigration
{

	public function safeUp()
	{
		if ($this->dbConnection->schema instanceof CMysqlSchema) {
			$options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8';
		} else {
			$options = '';
		}
		
		// Schema for "order" table
		$this->createTable('{{order}}', array(
			'id' => 'int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT',
			'nabor_year' => 'int(11) NOT NULL',
			'campaign_id' => 'int(11) NOT NULL',
			'name' => 'varchar(255) NOT NULL COMMENT "Наименование (текстовое описание) приказа"',
			'number' => 'varchar(255) COMMENT "Номер приказа"',
			'is_benefit' => 'tinyint(1) COMMENT "Признак приказа для льготников"',
			'stage' => 'tinyint(1) COMMENT "Этап приёма"',
			'learnform_id' => 'tinyint(4) COMMENT "Форма обучения (id)"',
			'type_degree_id' => 'tinyint(3) COMMENT "Уровень образования (id)"',
			'type_pay_id' => 'tinyint(4) COMMENT "Источник финансирования (id)"',
			'type_order_id' => 'tinyint(1) NOT NULL COMMENT "Тип приказа (о зачислении, об исключении) (id)"',
			'type_status_order_id' => 'tinyint(1) COMMENT "Статус приказа (id)"',
			'is_transferred' => 'tinyint(1) COMMENT "Признак, что передан в базу ГУПА"',
			'transfer_time' => 'datetime COMMENT "Дата передачи в базу СПбГУП"',
			'fis_is_transferred' => 'tinyint(1) COMMENT "Признак, передано ли базу ФИС ЕГЭ и приёма"',
			'fis_transfer_time' => 'datetime COMMENT "Дата и время передачи в ФИС"',
			'create_time' => 'datetime COMMENT "Дата и время создания"',
			'create_user_id' => 'int(11) COMMENT "Пользователь, создавший"',
			'update_time' => 'datetime COMMENT "Дата и время последнего изменения"',
			'update_user_id' => 'int(11) COMMENT "Пользователь, изменивший"',
			'registration_time' => 'datetime COMMENT "Дата регистрации приказа"',
			'registration_user_id' => 'int(11) COMMENT "Пользователь, зарегистрировавший приказ"',
			'publication_time' => 'datetime COMMENT "Дата фактической публикации приказа"',
			'publication_user_id' => 'int(11) COMMENT "Пользователь, опубликовавший приказ"'
		), $options . ' COMMENT="Приказы о зачислении/исключении"');
		
		// Schema and data for "type_order" table
		$this->createTable('{{type_order}}', array(
			'id' => 'tinyint(1) NOT NULL PRIMARY KEY',
			'name' => 'varchar(100) COMMENT "Название"'
		), $options . ' COMMENT="Справочник типов приказов"');
		
		$type_order = array(
			array(
				'id' => 1,
				'name' => 'приказ о зачислении'
			),
			array(
				'id' => 2,
				'name' => 'приказ об исключении из приказа о зачислении'
			)
		);
		foreach ($type_order as $item) {
			$this->insert('{{type_order}}', $item);
		}
		
		// Schema and data for "type_status_order" table
		$this->createTable('{{type_status_order}}', array(
			'id' => 'tinyint(1) NOT NULL PRIMARY KEY',
			'name' => 'varchar(50) COMMENT "Название"'
		), $options . ' COMMENT="Справочник статусов приказов"');
		
		$type_status_order = array(
			array(
				'id' => 1,
				'name' => 'создан'
			),
			array(
				'id' => 2,
				'name' => 'опубликован'
			)
		);
		foreach ($type_status_order as $item) {
			$this->insert('{{type_status_order}}', $item);
		}
		
		// Schema for "order_abit_spec" table
		
		$this->createTable('{{order_abit}}', array(
			'id' => 'int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT',
			'order_id' => 'int(11) NOT NULL COMMENT "Приказ (id)"',
			'abit_id' => 'int(11) NOT NULL COMMENT "Абитуриент (id)"',
			'abit_spec_id' => 'int(11) NOT NULL COMMENT "Специальность абитуриента (id)"',
			'type_order_id' => 'tinyint(1) NOT NULL COMMENT "Тип приказа (id)"',
			'nabor_autoid' => 'bigint(10) NOT NULL COMMENT "Элемента набора (id)"',
			'stage' => 'tinyint(1) COMMENT "Этап приёма"',
			'spec_id' => 'int(11) COMMENT "Специальность (id)"',
			'learnform_id' => 'tinyint(4) COMMENT "Форма обучения (id)"',
			'type_degree_id' => 'tinyint(3) COMMENT "Уровень образования (id)"',
			'type_pay_id' => 'tinyint(4) COMMENT "Источник финансирования (id)"',
			'type_fis_budget_level_id' => 'tinyint(1) COMMENT "Тип бюджета (ФИС) (id)"',
			'type_benefit_id' => 'tinyint(2) COMMENT "Тип льготы (id)"',
			'is_disagreed' => 'tinyint(1) COMMENT "Признак отказа от зачисления"',
			'is_disagreed_datetime' => 'datetime COMMENT "Дата отказа от зачисления"',
			'is_disagreed_user_id' => 'int(11) COMMENT "Пользователь, установивший признак отказа"'
		), $options . ' COMMENT="Заявления, включенные в приказы"');
		
		// Keys and indexes
		if (($this->dbConnection->schema instanceof CSqliteSchema) == false) {
			
			// For "order" table
			
			// FK
			$this->addForeignKey('fk_order_type_order_id', '{{order}}', 'type_order_id', '{{type_order}}', 'id', 'RESTRICT', 'CASCADE');
			$this->addForeignKey('fk_order_type_status_order_id', '{{order}}', 'type_status_order_id', '{{type_status_order}}', 'id', 'RESTRICT', 'CASCADE');
			$this->addForeignKey('fk_order_type_degree_id', '{{order}}', 'type_degree_id', '{{type_degree}}', 'id', 'RESTRICT', 'CASCADE');
			$this->addForeignKey('fk_order_campaign_id', '{{order}}', 'campaign_id', '{{campaign}}', 'id', 'RESTRICT', 'CASCADE');
			
			// IDX
			$this->createIndex('idx_order_nabor_year', '{{order}}', 'nabor_year');
			$this->createIndex('idx_order_learnform_id', '{{order}}', 'learnform_id');
			$this->createIndex('idx_order_type_pay_id', '{{order}}', 'type_pay_id');
			
			// For "order_abit" table
			
			// FK
			$this->addForeignKey('fk_order_abit_order_id', '{{order_abit}}', 'order_id', '{{order}}', 'id', 'RESTRICT', 'CASCADE');
			$this->addForeignKey('fk_order_abit_type_order_id', '{{order_abit}}', 'type_order_id', '{{type_order}}', 'id', 'RESTRICT', 'CASCADE');
			$this->addForeignKey('fk_order_abit_type_degree_id', '{{order_abit}}', 'type_degree_id', '{{type_degree}}', 'id', 'RESTRICT', 'CASCADE');
			$this->addForeignKey('fk_order_abit_abit_id', '{{order_abit}}', 'abit_id', '{{abit}}', 'id', 'RESTRICT', 'CASCADE');
			$this->addForeignKey('fk_order_abit_abit_spec_id', '{{order_abit}}', 'abit_spec_id', '{{abit_spec}}', 'id', 'RESTRICT', 'CASCADE');
		}
	}

	public function safeDown()
	{
		if (($this->dbConnection->schema instanceof CSqliteSchema) == false) {
			// order
			$this->dropForeignKey('fk_order_type_order_id', '{{order}}');
			$this->dropForeignKey('fk_order_type_status_order_id', '{{order}}');
			$this->dropForeignKey('fk_order_type_degree_id', '{{order}}');
			$this->dropForeignKey('fk_order_campaign_id', '{{order}}');
			// order_abit
			$this->dropForeignKey('fk_order_abit_order_id', '{{order_abit}}');
			$this->dropForeignKey('fk_order_abit_type_order_id', '{{order_abit}}');
			$this->dropForeignKey('fk_order_abit_type_degree_id', '{{order_abit}}');
			$this->dropForeignKey('fk_order_abit_abit_id', '{{order_abit}}');
			$this->dropForeignKey('fk_order_abit_abit_spec_id', '{{order_abit}}');
		}
		$this->dropTable('{{order}}');
		$this->dropTable('{{type_order}}');
		$this->dropTable('{{type_status_order}}');
		$this->dropTable('{{order_abit}}');
	}
}
/* ============================================================
 * Файл: protected/migrations/m160523_144601_permission_history.php
 * ============================================================ */


class m160523_144601_permission_history extends CDbMigration
{

	public function safeUp()
	{
		$this->insert('AuthItem', array(
			'name' => 'Abit.History',
			'type' => 0,
			'description' => 'Read abit history',
			'data' => 'N;'
		));
		$this->insert('AuthItemChild', array(
			'parent' => 'pricom-dir',
			'child' => 'Abit.History'
		));
	}

	public function safeDown()
	{
		$this->delete('AuthItemChild', 'child=:child', array(
			':child' => 'Abit.History'
		));
		$this->delete('AuthItem', 'name=:name', array(
			':name' => 'Abit.History'
		));
	}
}
/* ============================================================
 * Файл: protected/migrations/m180321_135938_data_auth_item_child.php
 * ============================================================ */


class m180321_135938_data_auth_item_child extends CDbMigration
{
	public function up()
	{
		$this->delete("AuthItemChild", "child=:child AND parent=:parent", array(
			":child" => "Abit.Stok",
			":parent" => "pricom"
		));
		$this->insert("AuthItemChild", array(
			"parent" => "pricom-dir",
			"child" => "Abit.Stok"
		));
	}

	public function down()
	{
		$this->delete("AuthItemChild", "child=:child AND parent=:parent", array(
			":child" => "Abit.Stok",
			":parent" => "pricom-dir"
		));
		$this->insert("AuthItemChild", array(
			"parent" => "pricom",
			"child" => "Abit.Stok"
		));
	}

	/*
	// Use safeUp/safeDown to do migration with transaction
	public function safeUp()
	{
	}

	public function safeDown()
	{
	}
	*/
}