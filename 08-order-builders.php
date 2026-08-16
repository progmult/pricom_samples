<?php
/**
 * ============================================================================
 * ПРИМЕР 8. Приказы о зачислении: Строитель для 30+ вариантов документа
 * ----------------------------------------------------------------------------
 * Источник: protected/components/order/*.php (33 класса)
 *
 * Приказ о зачислении — юридический документ с жёстко заданными
 * формулировками. Его вид определяется комбинацией: форма обучения
 * x источник финансирования x уровень образования x этап зачисления
 * x наличие льготы — больше тридцати вариантов. От комбинации зависит и текст
 * приказа, и критерии отбора абитуриентов, которых в него можно включить
 * (нужен ли оригинал документа, проверять ли оплату, какой статус конкурсного
 * отбора считается проходным). Правила приёма меняются ежегодно и точечно.
 *
 * Директор фиксирует протокол сборки (какие шаги и в каком порядке), иерархия
 * строителей переиспользует общее и переопределяет частное:
 *
 *   BuilderAdmission          (перечень шагов)
 *     -> BuilderAdmissionBase          (общее для всех приказов; опущен)
 *        -> BuilderAdmissionOch        (очная форма)
 *           -> BuilderAdmissionOchKcp  (+ бюджетные места)
 *              -> BuilderAdmissionOchKcpBak1Stage
 *
 * Конкретный приказ — класс на 20-30 строк. Требование "у магистров своя
 * формулировка" правится в одном файле и остальных приказов не касается.
 *
 * Выбор строителя по параметрам приказа делает Order::loadAdmissioner(): там
 * дерево if/switch на 90 строк, повторяющее ту же комбинаторику — самая слабая
 * часть решения, в коде на это стоит TODO.
 * ============================================================================
 */


/* ============================================================
 * Файл: protected/components/order/BuilderAdmission.php
 * ============================================================ */


/**
 * 
 * @author kapustinav
 * @property Admission $admission
 */
abstract class BuilderAdmission
{

	public $filial_id;

	public $nabor_year;

	public $learnform_id;

	public $type_degree_id;

	public $order_id;

	public $type_order_id;

	public $protocolTime;

	/**
	 *
	 * @var Admission
	 */
	protected $_admission;

	/**
	 *
	 * @return Admission
	 */
	public function getAdmission()
	{
		return $this->_admission;
	}

	public function createNewAdmission()
	{
		$this->_admission = new Admission();
	}

	abstract public function buildFilial();

	abstract public function buildNaborYear();

	abstract public function buildOrderId();

	abstract public function buildTypeOrder();

	abstract public function buildIsBenefit();

	abstract public function buildCheckPayments();

	abstract public function buildTypeStatusSpecPass();

	abstract public function buildLearnForm();

	abstract public function buildTypeDegree();

	abstract public function buildTypePay();

	abstract public function buildStage();

	abstract public function buildTplDegree();

	abstract public function buildTplLearnForm();

	abstract public function buildTplLearnFormDecl();

	abstract public function buildTplYear();

	abstract public function buildTplYearPeriod();

	abstract public function buildTplTypePay();

	abstract public function buildTplEntrance();

	abstract public function buildTplDogovor();

	abstract public function buildTplEnrollDate();

	abstract public function buildTplStartDate();

	abstract public function buildTplCondition();
}
/* ============================================================
 * Файл: protected/components/order/AdmissionDirector.php
 * ============================================================ */


/**
 * @author kapustinav
 * @property BuilderAdmission $builderAdmission
 * @property Admission $admission
 */
class AdmissionDirector
{

	private $_builderAdmission;

	public function setBuilderAdmission(BuilderAdmission $builder)
	{
		$this->_builderAdmission = $builder;
	}

	public function getAdmission()
	{
		return $this->_builderAdmission->getAdmission();
	}

	public function constructAdmission()
	{
		$this->_builderAdmission->createNewAdmission();
		
		$this->_builderAdmission->buildFilial();
		
		$this->_builderAdmission->buildNaborYear();
		
		$this->_builderAdmission->buildOrderId();
		
		$this->_builderAdmission->buildTypeOrder();
		
		$this->_builderAdmission->buildIsBenefit();
		
		$this->_builderAdmission->buildCheckPayments();
		
		$this->_builderAdmission->buildTypeStatusSpecPass();
		
		$this->_builderAdmission->buildLearnForm();
		
		$this->_builderAdmission->buildTypeDegree();
		
		$this->_builderAdmission->buildTypePay();
		
		$this->_builderAdmission->buildStage();
		
		$this->_builderAdmission->buildTplDegree();
		$this->_builderAdmission->buildTplLearnForm();
		$this->_builderAdmission->buildTplLearnFormDecl();
		$this->_builderAdmission->buildTplYear();
		$this->_builderAdmission->buildTplYearPeriod();
		$this->_builderAdmission->buildTplTypePay();
		$this->_builderAdmission->buildTplEntrance();
		$this->_builderAdmission->buildTplDogovor();
		$this->_builderAdmission->buildTplEnrollDate();
		$this->_builderAdmission->buildTplBenefit();
		$this->_builderAdmission->buildTplStartDate();
        $this->_builderAdmission->buildTplCondition();
	}

	public function createAdmission(BuilderAdmission $builder)
	{
		$this->setBuilderAdmission($builder);
		$this->constructAdmission();
		return $this->_builderAdmission->admission;
	}
}
/* ============================================================
 * Файл: protected/components/order/BuilderAdmissionOch.php
 * ============================================================ */


abstract class BuilderAdmissionOch extends BuilderAdmissionBase
{

	public function buildLearnForm()
	{
		$this->_admission->learnformId = GupLearnFormList::OCH;
	}

	public function buildTypeStatusSpecPass()
	{
		// $this->admission->typeStatusSpecPassId =;
	}

	public function buildTplLearnForm()
	{
		$this->_admission->tplLearnForm = 'очная';
	}

	public function buildTplLearnFormDecl()
	{
		$this->_admission->tplLearnFormDecl = 'очной';
	}

    public function buildTplEnrollDate()
    {
        $this->_admission->tplEnrollDate = ' с 1 сентября ' . $this->_admission->tplYear . ' года';
    }

    public function buildTplStartDate()
    {
        $this->_admission->tplStartDate = 'с 01 сентября ' . $this->_admission->tplYear . ' года в соответствии с утвержденными учебными планами.';
    }
}
/* ============================================================
 * Файл: protected/components/order/BuilderAdmissionOchKcp.php
 * ============================================================ */


abstract class BuilderAdmissionOchKcp extends BuilderAdmissionOch
{

	public function buildTypeStatusSpecPass()
	{
		// $this->admission->typeStatusSpecPassId =;
	}

	public function buildCheckPayments()
	{
		$this->_admission->needCheckPayments = 0;
	}

	public function buildTypePay()
	{
		$this->_admission->typePayId = GupTypePay::KCP;
	}

	public function buildTplTypePay()
	{
		$this->_admission->tplTypePay = 'на места в рамках КЦП (контрольные цифры приема)';
	}

	public function buildTplDogovor()
	{
		$this->_admission->tplDogovor = 'за счет средств федерального бюджета';
	}
}
/* ============================================================
 * Файл: protected/components/order/BuilderAdmissionOchKcpBak1Stage.php
 * ============================================================ */


/**
 * Включение в приказ абитуриентов:
 * - Очная
 * - КЦП
 * - Бакалавр
 * - Первый этап
 *
 */
class BuilderAdmissionOchKcpBak1Stage extends BuilderAdmissionOchKcp
{

	public function buildIsBenefit()
	{
		$this->_admission->isBenefit = 0;
	}

	public function buildTypeStatusSpecPass()
	{
		$this->_admission->typeStatusSpecPassId = '>0';
	}

	public function buildTypeDegree()
	{
		$this->_admission->typeDegreeId = TypeDegree::BAK;
	}

	public function buildStage()
	{
		$this->_admission->stage = 1;
	}
}