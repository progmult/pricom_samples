<?php
/**
 * ============================================================================
 * ПРИМЕР 6. Отчётность Росстата: один запрос вместо N запросов
 * ----------------------------------------------------------------------------
 * Источники: protected/models/Repository.php,
 *            protected/models/RepositoryReportVPO1.php
 *
 * Форма ВПО-1, раздел 2.8 — таблица из 17 строк: "принято всего", "на базе
 * среднего общего", "из них окончивших в отчётном году", "на базе СПО",
 * "на базе высшего: бакалавра / специалиста / магистра". Каждая строка — те же
 * четыре показателя, но по другой выборке.
 *
 * Вместо 17 запросов — ОДИН SQL с необязательными параметрами и таблица
 * параметров рядом с ним. Приём в условии:
 *
 *     AND (school.type_diplom_id = :p OR :p IS NULL)
 *
 * Параметр не задан — условие вырождается в TRUE и не сужает выборку. Код
 * метода читается как сам бланк отчёта: строка кода = строка формы, с тем же
 * комментарием, поэтому ежегодные правки формы проверяются глазами.
 *
 * Каждая строка считается сразу в двух разрезах — "принято заявлений" и
 * "зачислено приказом"; отличие ровно в одном INNER JOIN, поэтому SQL
 * параметризован и по нему тоже ($ordered).
 *
 * Отчёты вынесены из ActiveRecord в репозиторий: это агрегаты по 5-7 таблицам,
 * где AR не даёт ничего, кроме N+1. AR — для CRUD, SQL — для отчётности.
 * ============================================================================
 */


/* ============================================================
 * Файл: protected/models/Repository.php
 * ============================================================ */


class Repository
{
    protected function executeSelectSql($sql, $params = array())
    {
        return Yii::app()->db->createCommand($sql)->query($params)->readAll();
    }

    protected function executeSelectSqlOne($sql, $params = array())
    {
        return Yii::app()->db->createCommand($sql)->query($params)->read();
    }
}
/* ============================================================
 * Файл: protected/models/RepositoryReportVPO1.php
 * ============================================================ */


/**
 * Class RepositoryReportVPO1
 *
 * Форма ВПО-1
 * "Сведения об организации, осуществляющей образовательную деятельность по образовательным программам высшего образования
 * - программам бакалавриата, программам специалитета, программам магистратуры"
 * (утв. приказом Росстата от 15.08.2017 N 535)
 * https://normativ.kontur.ru/document?moduleId=44&documentId=37909
 */
class RepositoryReportVPO1 extends Repository
{
    protected function executeSelectSqlOne($sql, $params = array())
    {
        if (!is_array($sql)) {
            return parent::executeSelectSqlOne($sql, $params);
        } else {
            $res = array();
            foreach ($sql as $key => $sql_item) {
                $res[$key] = $this->executeSelectSqlOne($sql_item, $params);
            }
            return $res;
        }
    }

    /**
     * @param $filial_id
     * @param $year
     * @param $learnform_id
     * @return array
     */
    public function r28($filial_id, $year, $learnform_id)
    {
        $result = array();

        $paramsDefault = array(
            ':filial_id' => $filial_id,
            ':year' => $year,
            ':year_prev' => $year--,
            ':learnform_id' => $learnform_id,
            ':school_type_degree_id' => null,
            ':school_type_diplom_id' => null,
            ':is_school' => false,
            ':is_school_other' => false,
            ':school_name' => null,
            ':school_date' => false,
        );

        $result[1] = $this->executeSelectSqlOne($this->r28Sqls(), $paramsDefault);

        // на базе образования: среднего общего
        $result[2] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault, array(':school_type_diplom_id' => 2)));

        // на базе образования: среднего общего с датой
        $result[3] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault, array(':school_type_diplom_id' => 2, ':school_date' => true)));

        // на базе образования: общеобразовательных
        $result[4] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault,
                array(':school_type_diplom_id' => 2, ':school_date' => true, ':is_school' => true)));

        // на базе образования: общеобразовательных по адаптированным программам
        $result[5] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault,
                array(
                    ':school_type_diplom_id' => 2,
                    ':school_date' => true,
                    ':is_school' => true,
                    ':school_name' => '%интернат%'
                )));

        // на базе образования: других (кроме школ :)
        $result[7] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault,
                array(':school_type_diplom_id' => 2, ':school_date' => true, ':is_school_other' => true)));

        // на базе образования: СПО начальное (НПО)
        $result[8] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault, array(':school_type_diplom_id' => 10)));

        // на базе образования: СПО начальное (НПО) с датой
        $result[9] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault, array(':school_type_diplom_id' => 10, ':school_date' => true)));


        // на базе образования: СПО среднее
        $result[10] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault, array(':school_type_diplom_id' => 9)));

        // на базе образования: СПО с датой
        $result[11] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault, array(':school_type_diplom_id' => 9, ':school_date' => true)));

        // высшего, подтвержденного дипломом: бакалавра
        $result[12] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault, array(':school_type_diplom_id' => 8, ':school_type_degree_id' => 3)));

        // высшего, подтвержденного дипломом: бакалавра с датой
        $result[13] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault,
                array(':school_type_diplom_id' => 8, ':school_type_degree_id' => 3, ':school_date' => true)));

        // высшего, подтвержденного дипломом: специалиста
        $result[14] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault, array(':school_type_diplom_id' => 8, ':school_type_degree_id' => 5)));

        // высшего, подтвержденного дипломом: специалиста с датой
        $result[15] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault,
                array(':school_type_diplom_id' => 8, ':school_type_degree_id' => 5, ':school_date' => true)));

        // высшего, подтвержденного дипломом: магистра
        $result[16] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault, array(':school_type_diplom_id' => 8, ':school_type_degree_id' => 4)));

        // высшего, подтвержденного дипломом: магистра с датой
        $result[17] = $this->executeSelectSqlOne($this->r28Sqls(),
            array_merge($paramsDefault,
                array(':school_type_diplom_id' => 8, ':school_type_degree_id' => 4, ':school_date' => true)));

        return $result;
    }

    /**
     * @param $filial_id
     * @param $year
     * @param $learnform_id
     * @return array
     */
    public function r29($filial_id, $year, $learnform_id)
    {
        $result = array();

	/* ----- сокращено: r29() и r210() — разделы 2.9 и 2.10, устроены так же ----- */

    public function r28Sqls()
    {
        $result = array();
        $result['reg'] = $this->r28Sql();
        $result['ord'] = $this->r28Sql(true);
        return $result;
    }


	/* ----- сокращено: r29Sqls(), r210Sqls() ----- */


    /**
     * @param bool $ordered
     * @return string
     */
    private function r28Sql($ordered = false)
    {
        $innerJoinOrdered = ($ordered) ?
            'INNER JOIN pricom_order_abit as ordered
            ON aspec.id = ordered.abit_spec_id' : '';

        $sql = "
        
        SELECT
           Sum( IF ( spec_type_degree_id = 3 
           AND reg_pay_id = 0, 1, 0 ) ) AS bak_free_c,
           Sum( IF ( spec_type_degree_id = 3 
           AND reg_pay_id = 1, 1, 0 ) ) AS bak_pay_c,
           Sum( IF ( spec_type_degree_id = 5 
           AND reg_pay_id = 0, 1, 0 ) ) AS spec_free_c,
           Sum( IF ( spec_type_degree_id = 5 
           AND reg_pay_id = 1, 1, 0 ) ) AS spec_pay_c
        FROM
           (
              SELECT
                 aspec.id,
                 aspec.abit_id,
                 aspec.learnform_id,
                 aspec.type_degree_id AS spec_type_degree_id,
                 pay_reg.Pay_BaseID_FIS as reg_pay_id
              FROM
                 pricom_abit_spec AS aspec 
                 INNER JOIN
                    pricom_gup_type_pay AS pay_reg 
                    ON aspec.is_pay = pay_reg.pay_id
                 INNER JOIN
                    pricom_abit_school AS school 
                    ON school.id = aspec.abit_school_id
                INNER JOIN
                    pricom_gup_Abit_NameUchZav AS type_school
                    ON school.type_school_id = type_school.code_zav
                 $innerJoinOrdered
                INNER JOIN 
                    pricom_abit as abit
                    ON aspec.abit_id = abit.id
                 LEFT JOIN
                    pricom_type_degree 
                    ON school.type_degree_id = pricom_type_degree.id
              WHERE
                 aspec.learn_year = :year
                 AND aspec.filial_id = :filial_id
                 AND aspec.learnform_id = :learnform_id
                 AND aspec.registration_time IS NOT NULL
                 AND aspec.type_status_spec_id <> 40
                 AND 
                 (
                    aspec.type_degree_id = 3 
                    OR aspec.type_degree_id = 5 
                 )
                AND (school.type_degree_id = :school_type_degree_id OR :school_type_degree_id IS NULL)
                AND (school.type_diplom_id = :school_type_diplom_id OR :school_type_diplom_id IS NULL)
                AND (school.type_school_id IN (1,3) OR :is_school = false)
                AND (school.type_school_id NOT IN (1,3) OR :is_school_other = false)
                AND (school.school_name like :school_name OR :school_name IS NULL)
                AND (school.diplom_date BETWEEN '2018-10-01' AND '2019-09-30' OR :school_date = false)
              GROUP BY
                /*aspec.id*/
                aspec.abit_school_id,
                aspec.type_degree_id,
                aspec.spec_id,
                CASE aspec.is_pay WHEN 4 THEN 1 ELSE pay_reg.Pay_BaseID_FIS END
           )
           AS abits
        ";

        return $sql;
    }

	/* ----- сокращено: r29Sql(), r210Sql() ----- */

}
