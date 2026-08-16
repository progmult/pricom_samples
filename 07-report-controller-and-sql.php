<?php
/**
 * ============================================================================
 * ПРИМЕР 7. Контроллер отчётов и агрегирующие запросы
 * ----------------------------------------------------------------------------
 * Источники: protected/controllers/ReportController.php,
 *            protected/models/RepositoryReportGeneral.php
 *
 *  1. Контроллер отчётов ничего не считает: проверяет права, разрешает
 *     контекст (филиал + год + конкурс) и отдаёт результат в вид. Логика
 *     подсчёта — в репозитории, её можно дёрнуть из консоли или из теста.
 *  2. Отчёты закрыты ролью pricom-dir в контексте филиала: директор филиала
 *     видит цифры своего филиала и не видит чужие.
 *  3. Условная агрегация SUM(IF(...)): восемь показателей формы А считаются
 *     одним проходом по выборке, а не восемью запросами SELECT COUNT(*).
 *  4. В form2Sql средние баллы вынесены в отдельный подзапрос и подклеены
 *     LEFT JOIN-ом: баллы лежат на другом уровне детализации (испытание, а не
 *     заявление), и подсчёт их в основном запросе размножил бы строки и
 *     завысил все счётчики.
 * ============================================================================
 */


/* ============================================================
 * Файл: protected/controllers/ReportController.php
 * ============================================================ */


class ReportController extends YearProfileController
{
    /**
     * @var string the default layout for the views. Defaults to '//layouts/column2', meaning
     * using two-column layout. See 'protected/views/layouts/column2.php'.
     */
    public $layout = '//layouts/column1Admin';


    protected $reportRepository;

    public function filters()
    {
        return array(
            'yearContext', // проверка контекста года
            'filialContext', // проверка контекста филиала
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
            array(
                'allow', // allow users with role "pricom" to perform 'all' actions
                'roles' => array('pricom-dir' => array('filial' => $this->boundFilial)),
            ),
            array(
                'deny',  // deny all users
                'users' => array('*'),
            ),
        ));
    }

    public function actionFilters()
    {
        $this->render('filters');
    }

    public function actionIndex()
    {
        $this->render('index', array());
    }

    /**
     * Подсчитывает количество взятых на проверку абитуриентов по каждому сотруднику и дню
     */
    public function actionVerified()
    {
        $rows = $this->loadVerified();

        $this->render('verified', array(
            'rows' => $rows,
        ));
    }

    /**
     * Отчет для совета ректоров (форма А)
     */
    public function actionFormA()
    {
        $rowsKCP = $this->loadFormAforKCP();
        $rowsPay = $this->loadFormAforPay();
        $rowsMag = $this->loadFormAforMagFree();
        $rowsBu = $this->loadFormAforBu();

        $this->render('formA', array(
            'rowsKCP' => $rowsKCP,
            'rowsPay' => $rowsPay,
            'rowsMag' => $rowsMag,
            'rowsBu' => $rowsBu,
        ));
    }

    /**
     * Отчет для совета ректоров (форма 2)
     */
    public function actionForm2()
    {
        $rowsKCP = $this->loadForm2forKCP();
        $rowsKCP_spb = $this->loadForm2forKCP($isSpb = true);
        $rowsPay = $this->loadForm2forPay();
        $rowsPay_spb = $this->loadForm2forPay($isSpb = true);

        $this->render('form2', array(
            'rowsKCP' => $rowsKCP,
            'rowsKCP_spb' => $rowsKCP_spb,
            'rowsPay' => $rowsPay,
            'rowsPay_spb' => $rowsPay_spb,
        ));
    }

	/* ----- сокращено: actionEnrolledBalls*, actionMinobr*, actionRosstat28/29/210 ----- */



    protected function getReportRepository()
    {
        if (empty ($this->reportRepository)) {
            $this->reportRepository = new RepositoryReportGeneral ();
        }

        return $this->reportRepository;
    }

    protected function getRosstatRepository()
    {
        return new RepositoryReportVPO1();
    }

    /**
     * Возвращает результат запроса количества взятых на проверку абитуриентов по каждому сотруднику и дню
     */
    public function loadVerified()
    {
        return $this->getReportRepository()->verified();
    }

    /**
     *
     * @return Contest конкурс для очного КЦП текущего года и филиала
     */
    private function loadContestKCP()
    {
        return Contest::model()->with('contestItems')->find(array(
            'condition' => 'contestItems.pay_id = :pay_id AND t.filial_id = :filial_id AND t.nabor_year = :nabor_year',
            'params' => array(
                ':pay_id' => GupTypePay::KCP,
                ':filial_id' => $this->boundFilial->id,
                ':nabor_year' => $this->boundYear->id
            )
        ));
    }

    /**
     *
     * @return Contest конкурс для очного ПЛАТНОГО текущего года и филиала
     */
    private function loadContestPay()
    {
        return Contest::model()->with('contestItems')->find(array(
            'condition' => 'contestItems.pay_id = :pay_id AND t.filial_id = :filial_id AND t.nabor_year = :nabor_year',
            'params' => array(
                ':pay_id' => GupTypePay::PAY,
                ':filial_id' => $this->boundFilial->id,
                ':nabor_year' => $this->boundYear->id
            )
        ));
    }

    /**
     *
     * @return Contest конкурс для очной магистратуры текущего года и филиала
     */
    private function loadContestMagFree()
    {
        return Contest::model()->with('contestItems')->find(array(
            'condition' => 'contestItems.type_degree_id = :type_degree_id AND t.filial_id = :filial_id AND t.nabor_year = :nabor_year',
            'params' => array(
                ':type_degree_id' => TypeDegree::MAG,
                ':filial_id' => $this->boundFilial->id,
                ':nabor_year' => $this->boundYear->id
            )
        ));
    }

    /**
     *
     * @return Contest конкурс для очного БУ текущего года и филиала
     */
    private function loadContestBu()
    {
        return Contest::model()->with('contestItems')->find(array(
            'condition' => 'contestItems.pay_id = :pay_id AND t.filial_id = :filial_id AND t.nabor_year = :nabor_year',
            'params' => array(
                ':pay_id' => GupTypePay::UNI,
                ':filial_id' => $this->boundFilial->id,
                ':nabor_year' => $this->boundYear->id
            )
        ));
    }


    /**
     * @return array Возвращает результат запроса формы А для КЦП
     */
    public function loadFormAforKCP()
    {
        return $this->getReportRepository()->formA($this->loadContestKCP()->id);
    }

    /**
     * @return array Возвращает результат запроса формы А для ПЛАТНОГО
     */
    public function loadFormAforPay()
    {
        return $this->getReportRepository()->formA($this->loadContestPay()->id);
    }

    /**
     * @return array Возвращает результат запроса формы А для Магистратуры всей бюджетной

	/* ----- сокращено: loadEnrolledBalls*, loadMinobr*, loadRosstat*, loadLearnform ----- */

}

/* ============================================================
 * Файл: protected/models/RepositoryReportGeneral.php
 * ============================================================ */


class RepositoryReportGeneral extends Repository
{

    public function noStoryInFis()
    {
        return $this->executeSelectSql($this->noStoryInFisSql());
    }

    public function verified()
    {
        return $this->executeSelectSql($this->verifiedSql());
    }

    /**
     * TODO: убрать $contest_id, передавать сюда год, филиал, уровень образования, тип финансирования
     */
    public function formA($contest_id = null)
    {
        return $this->executeSelectSqlOne($this->formASql(), array(':contest_id' => $contest_id));
    }

    /**
     * TODO: убрать $contest_id, передавать сюда год, филиал, уровень образования, тип финансирования
     */
    public function form2($contest_id = null, $isSpb = false)
    {
        return $this->executeSelectSqlOne($this->form2Sql(), array(':contest_id' => $contest_id, ':isSpb' => $isSpb));
    }


	/* ----- сокращено: enrolledBalls*, minobr* — ещё восемь отчётов по той же схеме ----- */

    private function verifiedSql()
    {
        return '
			SELECT
				DATE(verify_time) AS ddmmyy,
				pricom_user.username,
				count(pricom_abit.id) as counter,
				pricom_abit.filial_id,
				pricom_abit.learn_year,
				pricom_abit.verify_time,
				pricom_abit.verify_user_id
				
				FROM
				pricom_abit
				INNER JOIN pricom_user ON pricom_abit.verify_user_id = pricom_user.id
				WHERE
				pricom_abit.filial_id = 0
				AND pricom_abit.learn_year = 2017
				GROUP BY
				verify_user_id,
				DATE(verify_time)
				ORDER BY
				ddmmyy DESC, counter DESC
		';
    }

    private function formASql()
    {
        return "
		SELECT
		Sum(IF(pricom_abit_spec.id > 0, 1, 0)) AS stud_c,	/* всего заявлений */
		Sum(IF(type_region_id = 1, 1, 0)) AS spb_c,		/* петербуржцы */
		Sum(IF(type_sex_id = 1, 1, 0)) AS man_c,		/* мужчины */
		
		Sum(
			IF (type_diplom_id = 2, 1, 0)
			) AS attestat_sre_c,						/* среднее полное общее */
			
		Sum(
			IF (type_diplom_id = 8, 1, 0)			/* высшее профессиональное */
			) AS diplom_vo_c,
				
		Sum(
			IF (type_diplom_id IN (9,10), 1, 0)
			) AS diplom_spo_npo_c,								/* среднее профессиональное или начальное профессиональное образование  */
					
		Sum(
			IF (citizen_id NOT IN (3,25,26), 1, 0)
			) AS foreigners_c,								/* иностранцы	*/
						
		Sum(
			IF (citizen_id IN (4, 5, 6, 7, 8, 9, 11, 12, 14, 15, 18, 20, 21, 23, 27), 1, 0)
			) AS ussr_c										/* из СССР (кроме России) */
							
		FROM
			pricom_gup_Nabor
		INNER JOIN pricom_abit_spec ON pricom_abit_spec.nabor_autoid = pricom_gup_Nabor.autoid
		INNER JOIN pricom_abit ON pricom_abit_spec.abit_id = pricom_abit.id
		INNER JOIN pricom_abit_school ON pricom_abit_school.id = pricom_abit_spec.abit_school_id
		WHERE
			pricom_gup_Nabor.contest_id = :contest_id
			AND pricom_abit_spec.type_status_spec_id > 10
			AND pricom_abit_spec.type_status_spec_id <> 40
			AND pricom_abit.type_status_id <> 40
			AND pricom_gup_Nabor.Pay_ID <> 3
		GROUP BY
		pricom_gup_Nabor.contest_id
		";
    }


	/* ----- сокращено: form2Sql() и остальные тексты запросов ----- */

}
